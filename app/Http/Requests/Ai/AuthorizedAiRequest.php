<?php

namespace App\Http\Requests\Ai;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Laravel\Sanctum\PersonalAccessToken;

abstract class AuthorizedAiRequest extends FormRequest
{
    /** @var list<string> */
    protected array $allowedRootKeys = [];

    public function authorize(): bool
    {
        $user = $this->user();

        if (! $user instanceof User
            || ! $user->isActive()
            || ! in_array($user->role, ['admin', 'superadmin'], true)) {
            return false;
        }

        $accessToken = $user->currentAccessToken();

        return ! $accessToken instanceof PersonalAccessToken || $user->tokenCan('ai:use');
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $unknownKeys = array_diff(array_keys($this->all()), $this->allowedRootKeys);

            foreach ($unknownKeys as $key) {
                $validator->errors()->add((string) $key, 'Dieses Feld ist fuer den AI-Endpunkt nicht erlaubt.');
            }
        });
    }

    /** @return array<string, int|float> */
    public function aiOptions(): array
    {
        $options = $this->validated('options', []);

        return is_array($options) ? $options : [];
    }

    /** @return array<string, array<int, string>> */
    protected function textRules(): array
    {
        return [
            'prompt' => ['bail', 'required', 'string', 'max:20000'],
            'system' => ['nullable', 'string', 'max:8000'],
            'options' => ['sometimes', 'array:temperature,max_completion_tokens'],
            'options.temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'options.max_completion_tokens' => ['sometimes', 'integer', 'min:1', 'max:2000'],
        ];
    }
}
