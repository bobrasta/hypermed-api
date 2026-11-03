<?php

namespace Hypermed\Messaging\Http\Controllers;

use Hypermed\Messaging\Exceptions\MessagingException;
use Hypermed\Messaging\Http\Middleware\EnsureConversationParticipant;
use Hypermed\Messaging\Http\Middleware\EnsureMessageParticipant;
use Hypermed\Messaging\Models\Message;
use Hypermed\Messaging\Models\Participant;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller as BaseController;
use Illuminate\Support\Facades\Gate;

abstract class Controller extends BaseController
{
    protected function userId(Request $request): int
    {
        return (int) $request->user()->getAuthIdentifier();
    }

    protected function participant(Request $request): Participant
    {
        return $request->attributes->get(EnsureConversationParticipant::ATTRIBUTE);
    }

    protected function message(Request $request): Message
    {
        return $request->attributes->get(EnsureMessageParticipant::ATTRIBUTE);
    }

    protected function authorizeGate(Request $request, string $ability, array $arguments = []): void
    {
        if (! Gate::forUser($request->user())->allows($ability, $arguments)) {
            throw MessagingException::forbidden();
        }
    }
}
