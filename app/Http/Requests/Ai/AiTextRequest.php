<?php

namespace App\Http\Requests\Ai;

class AiTextRequest extends AuthorizedAiRequest
{
    protected array $allowedRootKeys = ['prompt', 'system', 'options'];

    public function rules(): array
    {
        return $this->textRules();
    }
}
