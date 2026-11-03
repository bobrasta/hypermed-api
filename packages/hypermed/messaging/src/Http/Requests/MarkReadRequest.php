<?php

namespace Hypermed\Messaging\Http\Requests;

class MarkReadRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            'last_read_message_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
