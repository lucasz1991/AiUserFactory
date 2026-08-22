<?php

namespace App\Http\Requests\Ai;

class AiImageGenerationRequest extends AuthorizedAiRequest
{
    protected array $allowedRootKeys = ['prompt', 'options'];

    public function rules(): array
    {
        return [
            'prompt' => ['bail', 'required', 'string', 'max:10000'],
            'options' => ['sometimes', 'array:temperature,max_completion_tokens,seed'],
            'options.temperature' => ['sometimes', 'numeric', 'between:0,2'],
            'options.max_completion_tokens' => ['sometimes', 'integer', 'min:1', 'max:2000'],
            'options.seed' => ['sometimes', 'integer', 'min:0', 'max:2147483647'],
        ];
    }
}
