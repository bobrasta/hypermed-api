<?php

namespace Hypermed\Messaging\Http\Requests;

use Hypermed\Messaging\Support\MessagingConfig;
use Illuminate\Validation\Rule;

class StoreGroupConversationRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.(int) config('messaging.groups.max_title_length', 120)],
            'user_ids' => ['required', 'array', 'min:1', 'max:'.((int) config('messaging.groups.max_participants', 200) - 1)],
            'user_ids.*' => ['integer', 'distinct', Rule::exists(MessagingConfig::userModel(), MessagingConfig::userKey())],
        ];
    }
}
