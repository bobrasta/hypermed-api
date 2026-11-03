<?php

namespace Hypermed\Messaging\Http\Requests;

class SearchUsersRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:50'],
        ];
    }
}
