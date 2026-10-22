<?php

namespace App\Domain\Conversions\DTOs;

use App\Models\ConversionSourceConnectionVersion;

final readonly class ConversionWebhookVerification
{
    public function __construct(
        public bool $verified,
        public ?ConversionSourceConnectionVersion $version = null,
        public ?int $timestamp = null,
        public ?string $safeErrorCode = null,
    ) {}

    public function __toString(): string
    {
        return $this->verified ? '1' : '0';
    }
}
