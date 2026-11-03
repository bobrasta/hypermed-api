<?php

namespace Hypermed\Messaging\Http\Controllers;

use Hypermed\Messaging\Contracts\AuditPolicy;
use Hypermed\Messaging\Events\AuditAccessed;
use Hypermed\Messaging\Exceptions\MessagingException;
use Hypermed\Messaging\Http\Requests\ListConversationsRequest;
use Hypermed\Messaging\Http\Requests\ListMessagesRequest;
use Hypermed\Messaging\Http\Resources\AuditMessageResource;
use Hypermed\Messaging\Http\Resources\ConversationResource;
use Hypermed\Messaging\Http\Responses\ApiResponse;
use Hypermed\Messaging\Models\Conversation;
use Hypermed\Messaging\Models\Message;
use Hypermed\Messaging\Models\Participant;
use Hypermed\Messaging\Services\ConversationQuery;
use Hypermed\Messaging\Support\MessagingConfig;
use Hypermed\Messaging\Support\UserSummary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only chat review. Works only while the AuditPolicy is on AND the
 * reader passes `messaging.audit.view`. Nothing here sends, marks read or
 * changes delivery state, and every read fires AuditAccessed.
 */
class AuditController extends Controller
{
    public function __construct(
        private readonly ConversationQuery $query,
        private readonly AuditPolicy $policy,
    ) {}

    /** A person's chats, as their own chat list shows them. */
    public function conversations(ListConversationsRequest $request, string $user): JsonResponse
    {
        $this->authorizeAudit($request);
        $subject = MessagingConfig::userModel()::query()->find((int) $user);
        if (! $subject) {
            throw MessagingException::notFound('User not found.');
        }

        $limit = (int) ($request->validated('limit') ?? config('messaging.list.default_limit', 30));
        $page = $this->query->page((int) $user, $request->validated('cursor'), $limit);

        AuditAccessed::dispatch($this->userId($request), 'conversations', (int) $user);

        return ApiResponse::success(
            ConversationResource::collection($page['items'])->resolve($request),
            200,
            ['next_cursor' => $page['next_cursor'], 'subject' => UserSummary::make($subject)],
        );
    }

    /** One chat's messages, newest first (`before` pages back), deleted text and edits included. */
    public function messages(ListMessagesRequest $request, string $conversation): JsonResponse
    {
        $this->authorizeAudit($request);
        $conversationId = (int) $conversation;
        if (! Conversation::query()->whereKey($conversationId)->exists()) {
            throw MessagingException::notFound('Conversation not found.');
        }

        $limit = (int) ($request->validated('limit') ?? config('messaging.messages.default_limit', 50));
        $query = Message::query()->where('conversation_id', $conversationId)
            ->with([...Message::resourceRelations(), 'revisions']);
        if (($before = $request->validated('before')) !== null) {
            $query->where('id', '<', (int) $before);
        }
        $messages = $query->orderByDesc('id')->limit($limit + 1)->get();
        $hasMore = $messages->count() > $limit;

        // Everyone who was ever in it, including people who left.
        $participantIds = Participant::query()->where('conversation_id', $conversationId)
            ->orderBy('user_id')->pluck('user_id')->map(fn ($id) => (int) $id)->all();

        AuditAccessed::dispatch($this->userId($request), 'messages', null, $conversationId, $participantIds);

        return ApiResponse::success(
            AuditMessageResource::collection($messages->take($limit))->resolve($request),
            200,
            ['has_more' => $hasMore, 'participant_ids' => $participantIds],
        );
    }

    private function authorizeAudit(Request $request): void
    {
        if (! $this->policy->enabled()) {
            throw MessagingException::forbidden('Chat review is switched off.');
        }
        $this->authorizeGate($request, 'messaging.audit.view');
    }
}
