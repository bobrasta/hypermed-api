<?php

namespace Hypermed\Messaging\Http\Requests;

use Hypermed\Messaging\Exceptions\MessagingException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Base form request: authorization is done in services via gates/participation
 * checks (so errors carry the right `code`); validation errors use the envelope.
 */
abstract class MessagingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): void
    {
        throw MessagingException::validation($validator->errors()->toArray());
    }

    public function userId(): int
    {
        return (int) $this->user()->getAuthIdentifier();
    }
}
