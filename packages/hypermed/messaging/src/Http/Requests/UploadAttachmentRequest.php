<?php

namespace Hypermed\Messaging\Http\Requests;

class UploadAttachmentRequest extends MessagingRequest
{
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:'.(int) config('messaging.attachments.max_size_kb', 20480)],
            'client_uuid' => ['required', 'uuid'],
            'body' => ['nullable', 'string', 'max:'.(int) config('messaging.messages.max_body_length', 10000)],
            'reply_to_id' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
