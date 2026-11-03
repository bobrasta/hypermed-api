<?php

namespace Hypermed\Messaging\Http\Requests;

class MuteConversationRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            // null/absent = unmute
            'muted_until' => ['nullable', 'date'],
        ];
    }
}
