<?php

namespace Hypermed\Messaging\Calls\Exceptions;

use Hypermed\Messaging\Exceptions\MessagingException;

/**
 * Call domain errors; rendered with the package envelope {message, code} by
 * MessagingException::render().
 */
class CallException extends MessagingException
{
    public static function callNotFound(): self
    {
        return new self('Call not found.', 'not_found', 404);
    }

    public static function conversationNotFound(): self
    {
        return new self('Conversation not found.', 'not_found', 404);
    }

    public static function denied(string $message): self
    {
        return new self($message, 'forbidden', 403);
    }

    public static function notInConversation(): self
    {
        return new self('You are not a participant of this conversation.', 'not_participant', 403);
    }

    /** 503: LIVEKIT_URL / LIVEKIT_API_KEY / LIVEKIT_API_SECRET missing. */
    public static function unavailable(): self
    {
        return new self('Calls are not available right now.', 'calls_unavailable', 503);
    }

    /** 409: the action does not fit the call's current state (e.g. accept after it ended). */
    public static function invalidState(string $message): self
    {
        return new self($message, 'invalid_call_state', 409);
    }
}
