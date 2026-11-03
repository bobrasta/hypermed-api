<?php

namespace Hypermed\Messaging\Http\Requests;

class MarkDeliveredRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            'last_delivered_message_id' => ['required', 'integer', 'min:1'],
        ];
    }
}
