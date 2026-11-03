<?php

namespace Hypermed\Messaging\Exceptions;

use Hypermed\Messaging\Http\Responses\ApiResponse;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * Domain error rendered with the messaging envelope. Laravel calls render()
 * automatically, so the host's exception handler needs no changes.
 */
class MessagingException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly int $status,
        public readonly ?array $errors = null,
        public readonly array $headers = [],
    ) {
        parent::__construct($message);
    }

    public static function notParticipant(): self
    {
        return new self('You are not a participant of this conversation.', 'not_participant', 403);
    }

    public static function forbidden(string $message = 'This action is not allowed.'): self
    {
        return new self($message, 'forbidden', 403);
    }

    public static function notFound(string $message = 'Resource not found.'): self
    {
        return new self($message, 'not_found', 404);
    }

    public static function validation(array $errors, string $message = 'The given data was invalid.'): self
    {
        return new self($message, 'validation_failed', 422, $errors);
    }

    public static function rateLimited(int $retryAfter): self
    {
        return new self('Too many messages. Please slow down.', 'rate_limited', 429, null, [
            'Retry-After' => (string) $retryAfter,
        ]);
    }

    public function render(): JsonResponse
    {
        return ApiResponse::error($this->getMessage(), $this->errorCode, $this->status, $this->errors, $this->headers);
    }

    /** Domain errors are expected; don't fill the logs with them. */
    public function report(): bool
    {
        return true;
    }
}
