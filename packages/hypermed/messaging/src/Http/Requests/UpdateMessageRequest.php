<?php

namespace Hypermed\Messaging\Http\Requests;

class UpdateMessageRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string', 'max:'.(int) config('messaging.messages.max_body_length', 10000)],
        ];
    }
}
