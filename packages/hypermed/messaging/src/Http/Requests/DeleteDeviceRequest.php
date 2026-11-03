<?php

namespace Hypermed\Messaging\Http\Requests;

class DeleteDeviceRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:4096'],
        ];
    }
}
