<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Parité InternalMailController@reply (legacy) : client_id + content requis.
 */
class MailReplyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'client_id' => ['required', 'integer', 'min:1'],
            'content' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'client_id.required' => 'Champs manquants',
            'content.required' => 'Champs manquants',
        ];
    }
}
