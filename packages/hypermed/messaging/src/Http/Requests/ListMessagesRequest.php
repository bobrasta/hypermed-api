<?php

namespace Hypermed\Messaging\Http\Requests;

class ListMessagesRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            'before' => ['nullable', 'integer', 'min:1', 'prohibits:after'],
            'after' => ['nullable', 'integer', 'min:0'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('messaging.messages.max_limit', 100)],
        ];
    }
}
