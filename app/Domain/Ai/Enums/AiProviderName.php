<?php

namespace App\Domain\Ai\Enums;

enum AiProviderName: string
{
    case OpenRouter = 'openrouter';
    case Tavily = 'tavily';

    public function capability(): string
    {
        return match ($this) {
            self::OpenRouter => 'llm',
            self::Tavily => 'research',
        };
    }

    public function displayName(): string
    {
        return match ($this) {
            self::OpenRouter => 'OpenRouter',
            self::Tavily => 'Tavily',
        };
    }
}
