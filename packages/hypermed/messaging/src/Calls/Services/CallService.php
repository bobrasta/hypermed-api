<?php

namespace Hypermed\Messaging\Calls\Services;

use Carbon\CarbonInterface;
use Hypermed\Messaging\Calls\Events\CallAccepted;
use Hypermed\Messaging\Calls\Events\CallAnsweredElsewhere;
use Hypermed\Messaging\Calls\Events\CallCancelled;
use Hypermed\Messaging\Calls\Events\CallDeclined;
use Hypermed\Messaging\Calls\Events\CallEnded;
use Hypermed\Messaging\Calls\Events\CallIncoming;
use Hypermed\Messaging\Calls\Events\CallParticipantChanged;
use Hypermed\Messaging\Calls\Exceptions\CallException;
use Hypermed\Messaging\Calls\Jobs\RingTimeoutJob;
use Hypermed\Messaging\Calls\Jobs\SendCallPushJob;
use Hypermed\Messaging\Calls\LiveKit\LiveKitTokenIssuer;
use Hypermed\Messaging\Calls\Models\Call;
use Hypermed\Messaging\Calls\Models\CallParticipant;
use Hypermed\Messaging\Calls\Support\CallFormat;
use Hypermed\Messaging\Models\Conversation;
use Hypermed\Messaging\Services\MessagingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Throwable;

/**
 * Call state machine.
 *
 *   ringing ──accept──▶ ongoing ──last participant leaves / room_finished──▶ ended
 *      │ cancel (caller)          ▶ cancelled
 *      │ all invitees decline     ▶ declined
 *      │ ring timeout / room gone ▶ missed
 *
 * Every mutation runs in a transaction with the call row locked. Broadcast events are
 * ShouldDispatchAfterCommit; push / timeout jobs and the closing system message are
 * dispatched after commit as well.
 */
class CallService
{
    public function __construct(protected LiveKitTokenIssuer $tokens) {}

    // ---------------------------------------------------------------------
    // Authorization helpers
    // ---------------------------------------------------------------------

    public function ensureCanCall(Model $user): void
    {
        if (! $this->tokens->isConfigured()) {
            report(new \RuntimeException('LiveKit is not configured: set LIVEKIT_URL, LIVEKIT_API_KEY and LIVEKIT_API_SECRET.'));

            throw CallException::unavailable();
        }

        if (! Gate::forUser($user)->allows('messaging.call')) {
            throw CallException::denied('You are not allowed to make calls.');
        }
    }

    public function ensureConversationParticipant(int $conversationId, int $userId): Conversation
    {
        $conversation = Conversation::find($conversationId);
        if (! $conversation) {
            throw CallException::conversationNotFound();
        }
        if (! $conversation->isActiveParticipant($userId)) {
            throw CallException::notInConversation();
        }

        return $conversation;
    }

    /** A call is visible to active participants of its conversation. */
    public function findForUser(int $callId, int $userId): Call
    {
        $call = Call::find($callId);
        if (! $call) {
            throw CallException::callNotFound();
        }
        $this->ensureConversationParticipant($call->conversation_id, $userId);

        return $call;
    }

    // ---------------------------------------------------------------------
    // Actions
    // ---------------------------------------------------------------------

    /**
     * Start a call, or — when the conversation already has an active call — join that
     * one instead (single active call per conversation).
     *
     * @return array{call: Call, livekit: array, created: bool}
     */
    public function start(int $conversationId, Model $user, string $type): array
    {
        $userId = (int) $user->getKey();
        $this->ensureCanCall($user);
        $conversation = $this->ensureConversationParticipant($conversationId, $userId);

        [$call, $created] = DB::transaction(function () use ($conversation, $userId, $type) {
            // Serialize concurrent "start" requests for the same conversation.
            Conversation::query()->whereKey($conversation->getKey())->lockForUpdate()->first();

            $existing = Call::query()
                ->where('conversation_id', $conversation->getKey())
                ->active()
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->isRinging() && $this->ringSecondsRemaining($existing) <= 0) {
                // The timeout job never ran (worker down / sync queue): expire lazily.
                $this->expireRinging($existing);
                $existing = null;
            }

            if ($existing) {
                $this->joinLocked($existing, $userId);

                return [$existing, false];
            }

            $now = now();
            $call = Call::create([
                'conversation_id' => $conversation->getKey(),
                'initiated_by' => $userId,
                'type' => $type,
                'status' => Call::STATUS_RINGING,
                'started_at' => $now,
            ]);

            $inviteeIds = $conversation->activeParticipants()
                ->pluck('user_id')
                ->map(fn ($id) => (int) $id)
                ->reject(fn (int $id) => $id === $userId)
                ->unique()
                ->values()
                ->all();

            $rows = [[
                'call_id' => $call->id, 'user_id' => $userId, 'status' => CallParticipant::STATUS_JOINED,
                'joined_at' => $now, 'left_at' => null, 'created_at' => $now, 'updated_at' => $now,
            ]];
            foreach ($inviteeIds as $id) {
                $rows[] = [
                    'call_id' => $call->id, 'user_id' => $id, 'status' => CallParticipant::STATUS_RINGING,
                    'joined_at' => null, 'left_at' => null, 'created_at' => $now, 'updated_at' => $now,
                ];
            }
            CallParticipant::insert($rows);

            $call->load('participants.user');

            $this->broadcast(new CallIncoming($call, $inviteeIds));
            // The caller's other devices learn about the outgoing call too.
            $this->broadcast(new CallParticipantChanged($call, [$userId], $userId, CallParticipant::STATUS_JOINED));

            if ($inviteeIds !== []) {
                SendCallPushJob::dispatch($inviteeIds, $this->pushData($call, 'call.incoming'), true)->afterCommit();
            }

            RingTimeoutJob::dispatch($call->id)
                ->delay(now()->addSeconds($this->ringTimeoutSeconds()))
                ->afterCommit();

            return [$call, true];
        });

        return [
            'call' => $this->fresh($call),
            'livekit' => $this->tokens->forCall($call, $userId, CallFormat::userName($user)),
            'created' => $created,
        ];
    }

    /**
     * Accept (answer) a ringing call or (re)join an ongoing one.
     *
     * @return array{call: Call, livekit: array}
     */
    public function accept(int $callId, Model $user): array
    {
        $userId = (int) $user->getKey();
        $this->ensureCanCall($user);
        $call = $this->findForUser($callId, $userId);

        $call = DB::transaction(function () use ($call, $userId) {
            $call = $this->lock($call->id);
            if (! $call->isActive()) {
                throw CallException::invalidState('This call is no longer active.');
            }
            if ($call->isRinging() && $this->ringSecondsRemaining($call) <= 0) {
                $this->expireRinging($call);
                throw CallException::invalidState('This call is no longer active.');
            }

            $this->joinLocked($call, $userId);

            return $call;
        });

        return [
            'call' => $this->fresh($call),
            'livekit' => $this->tokens->forCall($call, $userId, CallFormat::userName($user)),
        ];
    }

    public function decline(int $callId, Model $user): Call
    {
        $userId = (int) $user->getKey();
        $call = $this->findForUser($callId, $userId);

        $call = DB::transaction(function () use ($call, $userId) {
            $call = $this->lock($call->id);
            $participant = $call->participantFor($userId);

            if (! $participant) {
                throw CallException::notInConversation();
            }
            if (! $call->isActive()) {
                return $call; // idempotent
            }
            if ($userId === $call->initiated_by && $call->isRinging()) {
                $this->cancelLocked($call);

                return $call;
            }
            if ($participant->isJoined()) {
                throw CallException::invalidState('You already joined this call; leave it instead.');
            }
            if (! $participant->isPending()) {
                return $call; // already declined / missed / left
            }

            $participant->update(['status' => CallParticipant::STATUS_DECLINED]);
            $call->load('participants.user');

            $this->broadcast(new CallDeclined($call, $this->participantIds($call), $userId));
            // Dismiss the ringing UI on the user's offline (push-only) devices.
            SendCallPushJob::dispatch([$userId], $this->pushData($call, 'call.answered_elsewhere'))->afterCommit();

            $this->settleRinging($call);

            return $call;
        });

        return $this->fresh($call);
    }

    public function cancel(int $callId, Model $user): Call
    {
        $userId = (int) $user->getKey();
        $call = $this->findForUser($callId, $userId);

        if ($call->initiated_by !== $userId) {
            throw CallException::denied('Only the caller can cancel this call.');
        }

        $call = DB::transaction(function () use ($call) {
            $call = $this->lock($call->id);
            if ($call->status === Call::STATUS_CANCELLED) {
                return $call; // idempotent
            }
            if (! $call->isRinging()) {
                throw CallException::invalidState('Only a ringing call can be cancelled.');
            }

            $this->cancelLocked($call);

            return $call;
        });

        return $this->fresh($call);
    }

    public function leave(int $callId, Model $user): Call
    {
        $userId = (int) $user->getKey();
        $call = $this->findForUser($callId, $userId);

        $call = DB::transaction(function () use ($call, $userId) {
            $call = $this->lock($call->id);
            $participant = $call->participantFor($userId);

            if (! $participant) {
                throw CallException::notInConversation();
            }
            if (! $call->isActive()) {
                return $call;
            }

            if ($userId === $call->initiated_by && $call->isRinging()) {
                $this->cancelLocked($call);

                return $call;
            }

            if ($participant->isPending()) {
                $participant->update(['status' => CallParticipant::STATUS_DECLINED]);
                $call->load('participants.user');
                $this->broadcast(new CallDeclined($call, $this->participantIds($call), $userId));
                $this->settleRinging($call);

                return $call;
            }

            if ($participant->isJoined()) {
                $this->markLeft($call, $participant, now(), false);
            }

            return $call;
        });

        return $this->fresh($call);
    }

    /** Called by RingTimeoutJob. */
    public function ringTimeout(int $callId): void
    {
        DB::transaction(function () use ($callId) {
            $call = Call::query()->whereKey($callId)->lockForUpdate()->first();
            if (! $call || ! $call->isActive()) {
                return;
            }
            $call->load('participants.user');
            $this->expireRinging($call);
        });
    }

    // ---------------------------------------------------------------------
    // LiveKit webhook handlers (crash recovery)
    // ---------------------------------------------------------------------

    public function handleRoomFinished(int $callId): void
    {
        DB::transaction(function () use ($callId) {
            $call = Call::query()->whereKey($callId)->lockForUpdate()->first();
            if (! $call || ! $call->isActive()) {
                return;
            }
            $call->load('participants.user');

            $this->finish(
                $call,
                $call->isRinging() ? Call::STATUS_MISSED : Call::STATUS_ENDED,
                'room_finished',
            );
        });
    }

    public function handleParticipantLeft(int $callId, int $userId, ?CarbonInterface $at = null): void
    {
        DB::transaction(function () use ($callId, $userId, $at) {
            $call = Call::query()->whereKey($callId)->lockForUpdate()->first();
            if (! $call || ! $call->isActive()) {
                return;
            }
            $call->load('participants.user');
            $participant = $call->participantFor($userId);

            // While ringing the caller sits alone in the room; a brief disconnect must not
            // cancel the call — room_finished / ring timeout cover a real crash.
            if (! $participant || ! $participant->isJoined() || $call->isRinging()) {
                return;
            }
            // Ignore stale events (participant rejoined after this event was emitted).
            if ($at && $participant->joined_at && $participant->joined_at->gt($at)) {
                return;
            }

            $this->markLeft($call, $participant, $at ? Carbon::instance($at) : now(), true);
        });
    }

    public function handleParticipantJoined(int $callId, int $userId, ?CarbonInterface $at = null): void
    {
        DB::transaction(function () use ($callId, $userId, $at) {
            $call = Call::query()->whereKey($callId)->lockForUpdate()->first();
            if (! $call || $call->status !== Call::STATUS_ONGOING) {
                return;
            }
            $call->load('participants.user');
            $participant = $call->participantFor($userId);

            // Only repair a participant we wrongly marked as left (e.g. reconnect race).
            if (! $participant || $participant->status !== CallParticipant::STATUS_LEFT) {
                return;
            }
            if ($at && $participant->left_at && $participant->left_at->gt($at)) {
                return;
            }

            $participant->update([
                'status' => CallParticipant::STATUS_JOINED,
                'joined_at' => $at ? Carbon::instance($at) : now(),
                'left_at' => null,
            ]);
            $call->load('participants.user');
            $this->broadcast(new CallParticipantChanged($call, $this->participantIds($call), $userId, CallParticipant::STATUS_JOINED));
        });
    }

    // ---------------------------------------------------------------------
    // Queries
    // ---------------------------------------------------------------------

    public function ringTimeoutSeconds(): int
    {
        return max(1, (int) config('messaging-calls.ring_timeout', 45));
    }

    public function ringSecondsRemaining(Call $call): int
    {
        $deadline = ($call->started_at ?? $call->created_at)->copy()->addSeconds($this->ringTimeoutSeconds());

        return max(0, (int) ceil(now()->diffInSeconds($deadline, false)));
    }

    // ---------------------------------------------------------------------
    // Internals (must run inside a transaction holding the call lock)
    // ---------------------------------------------------------------------

    protected function lock(int $callId): Call
    {
        $call = Call::query()->whereKey($callId)->lockForUpdate()->firstOrFail();
        $call->load('participants.user');

        return $call;
    }

    protected function fresh(Call $call): Call
    {
        return $call->fresh(['participants.user']) ?? $call;
    }

    /** Mark $userId as joined (answer, late join or rejoin). Caller of a ringing call: no-op. */
    protected function joinLocked(Call $call, int $userId): void
    {
        $call->loadMissing('participants.user');
        $participant = $call->participantFor($userId);

        if ($participant && $participant->isJoined()) {
            return; // reconnect: just a new token
        }

        $now = now();
        $wasPending = $participant?->isPending() ?? false;

        if ($participant) {
            $participant->update(['status' => CallParticipant::STATUS_JOINED, 'joined_at' => $now, 'left_at' => null]);
        } else {
            // Joined the conversation after the call started (group call late join).
            CallParticipant::create([
                'call_id' => $call->id, 'user_id' => $userId,
                'status' => CallParticipant::STATUS_JOINED, 'joined_at' => $now,
            ]);
        }

        if ($call->isRinging() && $userId !== $call->initiated_by) {
            $call->update(['status' => Call::STATUS_ONGOING, 'answered_at' => $now]);
        }

        $call->load('participants.user');
        $recipients = $this->participantIds($call);

        if ($wasPending) {
            $this->broadcast(new CallAccepted($call, $recipients, $userId));
            $this->broadcast(new CallAnsweredElsewhere($call->id, $userId));
            SendCallPushJob::dispatch([$userId], $this->pushData($call, 'call.answered_elsewhere'))->afterCommit();
        }
        $this->broadcast(new CallParticipantChanged($call, $recipients, $userId, CallParticipant::STATUS_JOINED));
    }

    protected function cancelLocked(Call $call): void
    {
        $this->finish($call, Call::STATUS_CANCELLED, 'cancelled');
    }

    /** After a decline: a ringing call with nobody left to answer is "declined". */
    protected function settleRinging(Call $call): void
    {
        if (! $call->isRinging()) {
            return;
        }

        $invitees = $call->participants->reject(fn (CallParticipant $p) => $p->user_id === $call->initiated_by);
        $stillPending = $invitees->contains(fn (CallParticipant $p) => $p->isPending());
        $anyJoined = $invitees->contains(fn (CallParticipant $p) => $p->isJoined());

        if (! $stillPending && ! $anyJoined) {
            $this->finish($call, Call::STATUS_DECLINED, 'declined');
        }
    }

    /** Ring timeout: pending invitees → missed; a call nobody answered → missed. */
    protected function expireRinging(Call $call): void
    {
        $call->loadMissing('participants.user');

        if ($call->isRinging()) {
            $this->finish($call, Call::STATUS_MISSED, 'no_answer');

            return;
        }

        $missed = [];
        foreach ($call->participants as $p) {
            if ($p->isPending()) {
                $p->update(['status' => CallParticipant::STATUS_MISSED]);
                $missed[] = (int) $p->user_id;
            }
        }
        if ($missed === []) {
            return;
        }

        $call->load('participants.user');
        $recipients = $this->participantIds($call);
        foreach ($missed as $id) {
            $this->broadcast(new CallParticipantChanged($call, $recipients, $id, CallParticipant::STATUS_MISSED));
        }
        SendCallPushJob::dispatch($missed, $this->pushData($call, 'call.ended'))->afterCommit();
    }

    protected function markLeft(Call $call, CallParticipant $participant, Carbon $at, bool $fromWebhook): void
    {
        $participant->update(['status' => CallParticipant::STATUS_LEFT, 'left_at' => $at]);
        $call->load('participants.user');

        $this->broadcast(new CallParticipantChanged($call, $this->participantIds($call), (int) $participant->user_id, CallParticipant::STATUS_LEFT));

        $joined = $call->participants->filter(fn (CallParticipant $p) => $p->isJoined())->count();

        // 1:1 calls end as soon as one side hangs up (explicit leave only; a webhook
        // "left" may be a reconnect, so then we wait until the room is really empty).
        $isDirect = ! $fromWebhook && $call->conversation?->type === 'direct';

        if ($joined === 0 || ($isDirect && $joined <= 1)) {
            $this->finish($call, Call::STATUS_ENDED, $fromWebhook ? 'all_left' : 'completed');
        }
    }

    /**
     * Move the call into a terminal status, settle participant statuses, broadcast,
     * push dismissals and post the closing system message (after commit).
     */
    protected function finish(Call $call, string $status, string $reason): void
    {
        $now = now();
        $wasPending = [];

        foreach ($call->participants as $p) {
            if ($p->isPending()) {
                $wasPending[] = (int) $p->user_id;
                $p->update(['status' => CallParticipant::STATUS_MISSED]);
            } elseif ($p->isJoined()) {
                $p->update(['status' => CallParticipant::STATUS_LEFT, 'left_at' => $now]);
            }
        }

        $call->update(['status' => $status, 'ended_at' => $now, 'end_reason' => $reason]);
        $call->load('participants.user');
        $recipients = $this->participantIds($call);

        if ($status === Call::STATUS_CANCELLED) {
            $this->broadcast(new CallCancelled($call, $recipients));
            $pushKind = 'call.cancelled';
        } else {
            $this->broadcast(new CallEnded($call, $recipients));
            $pushKind = 'call.ended';
        }

        if ($wasPending !== []) {
            // Dismiss full-screen ringing on devices that got the incoming push.
            SendCallPushJob::dispatch($wasPending, $this->pushData($call, $pushKind))->afterCommit();
        }

        $this->postSystemMessage($call);
    }

    protected function postSystemMessage(Call $call): void
    {
        $conversationId = (int) $call->conversation_id;
        $duration = $call->durationSeconds();
        $kind = CallFormat::kind($call->type);

        $body = match ($call->status) {
            Call::STATUS_ENDED => ucfirst($kind).' · '.CallFormat::duration($duration),
            Call::STATUS_DECLINED => 'Declined '.$kind,
            Call::STATUS_FAILED => ucfirst($kind).' failed',
            default => 'Missed '.$kind, // missed, cancelled
        };

        $meta = [
            'call_id' => (int) $call->id,
            'call_type' => $call->type,
            'duration_seconds' => $duration,
            'status' => $call->status,
        ];

        DB::afterCommit(function () use ($conversationId, $body, $meta) {
            try {
                app(MessagingService::class)->sendSystemMessage($conversationId, $body, $meta);
            } catch (Throwable $e) {
                report($e);
            }
        });
    }

    /**
     * Broadcast after commit. A broadcaster outage (Reverb down) is reported but never
     * fails the request: the state is committed and clients re-sync via GET /calls/{id}.
     */
    protected function broadcast(object $event): void
    {
        DB::afterCommit(static function () use ($event) {
            rescue(static fn () => event($event), null, true);
        });
    }

    /** @return list<int> */
    protected function participantIds(Call $call): array
    {
        return $call->participants->pluck('user_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    /** FCM data payload (all strings). */
    public function pushData(Call $call, string $kind): array
    {
        $caller = $call->participants->firstWhere('user_id', $call->initiated_by);

        return [
            'kind' => $kind,
            'call_id' => (string) $call->id,
            'conversation_id' => (string) $call->conversation_id,
            'call_type' => (string) $call->type,
            'caller_id' => (string) $call->initiated_by,
            'caller_name' => CallFormat::userName($caller?->user),
        ];
    }
}
