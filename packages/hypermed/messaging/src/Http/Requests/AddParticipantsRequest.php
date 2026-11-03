<?php

namespace Hypermed\Messaging\Http\Requests;

use Hypermed\Messaging\Support\MessagingConfig;
use Illuminate\Validation\Rule;

class AddParticipantsRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            'user_ids' => ['required', 'array', 'min:1', 'max:'.(int) config('messaging.groups.max_participants', 200)],
            'user_ids.*' => ['integer', 'distinct', Rule::exists(MessagingConfig::userModel(), MessagingConfig::userKey())],
        ];
    }
}
