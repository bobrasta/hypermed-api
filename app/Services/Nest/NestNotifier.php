<?php

namespace App\Services\Nest;

use App\Models\AppNotification;
use App\Models\NestTender;
use App\Models\Setting;
use App\Models\User;
use App\Services\EffectivePermissionResolver;
use Illuminate\Support\Carbon;

/**
 * In-app notifications (and, through AppNotificationObserver, their email
 * copies) for the NeST tracker. Goes to everyone who can see tenders, each
 * filtered by their own toggles: new relevant tenders, changes on tenders
 * being worked on, and the 07:30 digest.
 */
class NestNotifier
{
    public const PREFS = ['new_relevant' => true, 'changes' => true, 'digest' => true];

    /** Above this many new relevant tenders in one run, send one summary instead. */
    private const BATCH = 5;

    public static function prefsFor(int $userId): array
    {
        $saved = json_decode((string) Setting::get("nest.notify.{$userId}", '{}'), true) ?: [];

        return array_merge(self::PREFS, array_intersect_key($saved, self::PREFS));
    }

    public static function savePrefs(int $userId, array $prefs): array
    {
        $merged = array_merge(self::prefsFor($userId), array_map('boolval', array_intersect_key($prefs, self::PREFS)));
        Setting::set("nest.notify.{$userId}", json_encode($merged), $userId);

        return $merged;
    }

    /** @return list<int> users who can see NeST tenders and want this kind of alert */
    public function recipients(string $pref): array
    {
        $resolver = app(EffectivePermissionResolver::class);

        return User::where('is_active', true)->get()
            ->filter(fn (User $u) => $u->isAdminTier() || $resolver->can($u, 'screens.tenders') || $resolver->can($u, 'tenders.manage'))
            ->filter(fn (User $u) => self::prefsFor($u->id)[$pref])
            ->pluck('id')->values()->all();
    }

    /**
     * @param  list<NestTender>  $newRelevant
     * @param  list<array{tender: NestTender, diffs: list<array>}>  $changed
     */
    public function afterSync(array $newRelevant, array $changed, bool $firstRun = false): void
    {
        if ($newRelevant) {
            $users = $this->recipients('new_relevant');
            if ($firstRun || count($newRelevant) > self::BATCH) {
                $open = collect($newRelevant)->filter(fn ($t) => $t->closing_at?->isFuture())->count();
                $this->send($users, 'nest_tender_new', count($newRelevant) . ' new relevant tenders on NeST',
                    ($firstRun ? 'First NeST check: ' : '') . "{$open} still open. Open NeST tenders in Hypermed to review them.", null);
            } else {
                foreach ($newRelevant as $t) {
                    $this->send($users, 'nest_tender_new', $t->procuring_entity_name ?: 'New NeST tender',
                        $t->description . ' — ' . $this->closesIn($t->closing_at), $t->id);
                }
            }
            NestTender::whereIn('id', collect($newRelevant)->pluck('id'))->update(['notified_at' => now()]);
        }

        if ($changed) {
            $users = $this->recipients('changes');
            foreach ($changed as $c) {
                $t = $c['tender'];
                $to = array_values(array_unique(array_filter([...$users, $t->assigned_to && self::prefsFor($t->assigned_to)['changes'] ? $t->assigned_to : null])));
                $this->send($to, 'nest_tender_changed', 'NeST change: ' . ($t->entity_number ?: $t->procuring_entity_name),
                    $t->description . '. ' . collect($c['diffs'])->map(fn ($d) => $this->describe($d))->implode(' '), $t->id);
            }
        }
    }

    /** Relevant tenders closing within 3 days still new or under review. */
    public function sendDigest(): int
    {
        $rows = NestTender::relevant()->closingSoon()->whereNull('tender_id')
            ->whereIn('triage_status', ['new', 'reviewing'])->orderBy('closing_at')->get();
        if ($rows->isEmpty()) {
            return 0;
        }

        $lines = $rows->take(8)->map(fn ($t) => '• ' . $t->description . ' (' . $t->procuring_entity_name . ') — ' . $this->closesIn($t->closing_at))->implode("\n");
        $more = $rows->count() > 8 ? "\n…and " . ($rows->count() - 8) . ' more.' : '';
        $this->send($this->recipients('digest'), 'nest_tender_digest',
            $rows->count() . ' relevant NeST ' . ($rows->count() === 1 ? 'tender closes' : 'tenders close') . ' within 3 days',
            $lines . $more, $rows->count() === 1 ? $rows->first()->id : null);

        return $rows->count();
    }

    public function closesIn(?Carbon $at): string
    {
        if (! $at) {
            return 'no closing date';
        }
        $tz = config('nest.timezone');
        $days = (int) now($tz)->startOfDay()->diffInDays($at->copy()->setTimezone($tz)->startOfDay(), false);
        $when = $at->copy()->setTimezone($tz)->format('j M Y H:i');

        return match (true) {
            $days < 0 => "closed {$when}",
            $days === 0 => "closes today {$at->copy()->setTimezone($tz)->format('H:i')}",
            $days === 1 => "closes tomorrow ({$when})",
            default => "closes in {$days} days ({$when})",
        };
    }

    private function describe(array $d): string
    {
        $tz = config('nest.timezone');

        return match ($d['field']) {
            'closing_at' => 'Closing date moved from ' . ($d['old'] ? Carbon::parse($d['old'], 'UTC')->setTimezone($tz)->format('j M H:i') : 'none')
                . ' to ' . ($d['new'] ? Carbon::parse($d['new'], 'UTC')->setTimezone($tz)->format('j M H:i') : 'none') . '.',
            'has_addendum' => $d['new'] === 'yes' ? 'An addendum was added.' : 'Addendum flag cleared.',
            'lot_count' => "Lots changed from {$d['old']} to {$d['new']}.",
            'description' => 'Description changed.',
            default => ucfirst($d['field']) . ' changed.',
        };
    }

    private function send(array $userIds, string $type, string $title, string $body, ?int $nestTenderId): void
    {
        foreach (array_unique($userIds) as $uid) {
            AppNotification::create([
                'user_id' => $uid, 'type' => $type,
                'title' => mb_substr($title, 0, 255), 'body' => $body,
                'entity_type' => 'nest_tender', 'entity_id' => $nestTenderId,
            ]);
        }
    }
}
