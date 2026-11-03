<?php

namespace Hypermed\Messaging\Http\Requests;

use Hypermed\Messaging\Models\DeviceToken;

class RegisterDeviceRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'max:4096'],
            'platform' => ['required', 'in:'.implode(',', DeviceToken::PLATFORMS)],
        ];
    }
}
