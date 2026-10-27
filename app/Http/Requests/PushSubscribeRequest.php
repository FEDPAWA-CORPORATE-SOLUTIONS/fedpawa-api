<?php

namespace App\Http\Requests;

class PushSubscribeRequest extends ApiFormRequest
{
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'string', 'max:2048', 'starts_with:https://'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ];
    }
}
