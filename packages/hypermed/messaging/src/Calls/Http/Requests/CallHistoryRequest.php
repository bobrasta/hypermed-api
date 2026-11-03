<?php

namespace Hypermed\Messaging\Calls\Http\Requests;

use Hypermed\Messaging\Http\Requests\MessagingRequest;

class CallHistoryRequest extends MessagingRequest
{
    public function rules(): array
    {
        $max = (int) config('messaging-calls.history_max_limit', 50);

        return [
            'conversation_id' => ['required', 'integer', 'min:1'],
            'cursor' => ['nullable', 'string', 'max:512'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:'.$max],
        ];
    }
}
