<?php

namespace App\Http\Requests\Ai;

class AiJsonRequest extends AuthorizedAiRequest
{
    protected array $allowedRootKeys = ['prompt', 'system', 'options'];

    public function rules(): array
    {
        return $this->textRules();
    }
}
