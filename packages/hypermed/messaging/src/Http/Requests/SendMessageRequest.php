<?php

namespace Hypermed\Messaging\Http\Requests;

class SendMessageRequest extends MessagingRequest
{
    public function rules(): array
    {
        $max = (int) config('messaging.messages.max_body_length', 10000);

        return [
            'client_uuid' => ['required', 'uuid'],
            'type' => ['required', 'in:text,entity_ref'],
            'body' => ['required_if:type,text', 'nullable', 'string', 'max:'.$max],
            'reply_to_id' => ['nullable', 'integer', 'min:1'],
            'meta' => ['nullable', 'array'],
            'meta.entity_type' => ['required_if:type,entity_ref', 'string', 'max:100'],
            'meta.entity_id' => ['required_if:type,entity_ref', 'integer', 'min:1'],
        ];
    }

    /** @return array{client_uuid: string, type: string, body: ?string, reply_to_id: ?int, meta: ?array} */
    public function payload(): array
    {
        return [
            'client_uuid' => strtolower((string) $this->input('client_uuid')),
            'type' => (string) $this->input('type'),
            'body' => $this->input('body'),
            'reply_to_id' => $this->filled('reply_to_id') ? (int) $this->input('reply_to_id') : null,
            'meta' => $this->input('meta') ?: null,
        ];
    }
}
