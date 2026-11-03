<?php

namespace Hypermed\Messaging\Http\Requests;

class ListConversationsRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            'cursor' => ['nullable', 'string', 'max:500'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.(int) config('messaging.list.max_limit', 50)],
        ];
    }
}
