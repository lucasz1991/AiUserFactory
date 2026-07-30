<?php

namespace App\Http\Requests;

use App\Rules\PushEndpoint;
use App\Rules\WebPushKey;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StorePushSubscriptionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'endpoint' => ['required', 'url', 'max:4096', new PushEndpoint],
            'keys' => ['required', 'array'],
            'keys.p256dh' => ['required', 'string', 'max:1024', new WebPushKey('p256dh')],
            'keys.auth' => ['required', 'string', 'max:512', new WebPushKey('auth')],
            'contentEncoding' => ['nullable', Rule::in(['aes128gcm', 'aesgcm'])],
            'platform' => ['nullable', Rule::in(['ios', 'android', 'desktop', 'unknown'])],
            'browser' => ['nullable', 'string', 'max:60'],
            'deviceName' => ['nullable', 'string', 'max:100'],
        ];
    }
}
