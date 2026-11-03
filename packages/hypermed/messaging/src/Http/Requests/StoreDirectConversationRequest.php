<?php

namespace Hypermed\Messaging\Http\Requests;

use Hypermed\Messaging\Support\MessagingConfig;
use Illuminate\Validation\Rule;

class StoreDirectConversationRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'min:1', Rule::exists(MessagingConfig::userModel(), MessagingConfig::userKey())],
        ];
    }
}
