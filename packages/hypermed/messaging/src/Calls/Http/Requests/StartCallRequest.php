<?php

namespace Hypermed\Messaging\Calls\Http\Requests;

use Hypermed\Messaging\Calls\Models\Call;
use Hypermed\Messaging\Http\Requests\MessagingRequest;
use Illuminate\Validation\Rule;

class StartCallRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(Call::TYPES)],
        ];
    }
}
