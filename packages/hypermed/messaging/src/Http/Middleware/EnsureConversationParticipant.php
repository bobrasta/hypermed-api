<?php

namespace Hypermed\Messaging\Http\Middleware;

use Closure;
use Hypermed\Messaging\Services\ConversationService;
use Illuminate\Http\Request;

/**
 * Runs before Form Request validation so non-participants always get
 * 403 not_participant and learn nothing from validation errors.
 * Stores the active Participant (with conversation) in request attributes.
 */
class EnsureConversationParticipant
{
    public const ATTRIBUTE = 'messaging.participant';

    public function __construct(private readonly ConversationService $conversations) {}

    public function handle(Request $request, Closure $next)
    {
        $participant = $this->conversations->participantOrFail(
            (int) $request->route('conversation'),
            (int) $request->user()->getAuthIdentifier(),
        );
        $request->attributes->set(self::ATTRIBUTE, $participant);

        return $next($request);
    }
}
