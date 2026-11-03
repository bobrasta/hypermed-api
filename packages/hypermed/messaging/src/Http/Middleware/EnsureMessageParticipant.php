<?php

namespace Hypermed\Messaging\Http\Middleware;

use Closure;
use Hypermed\Messaging\Exceptions\MessagingException;
use Hypermed\Messaging\Models\Message;
use Hypermed\Messaging\Services\ConversationService;
use Illuminate\Http\Request;

/** Resolves {message} and requires active participation in its conversation. */
class EnsureMessageParticipant
{
    public const ATTRIBUTE = 'messaging.message';

    public function __construct(private readonly ConversationService $conversations) {}

    public function handle(Request $request, Closure $next)
    {
        $message = Message::query()->find((int) $request->route('message'))
            ?? throw MessagingException::notFound('Message not found.');

        $participant = $this->conversations->participantOrFail(
            (int) $message->conversation_id,
            (int) $request->user()->getAuthIdentifier(),
        );
        $request->attributes->set(self::ATTRIBUTE, $message);
        $request->attributes->set(EnsureConversationParticipant::ATTRIBUTE, $participant);

        return $next($request);
    }
}
