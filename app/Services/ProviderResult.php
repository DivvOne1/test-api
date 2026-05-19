<?php

namespace App\Services;

class ProviderResult
{
    public function __construct(
        public readonly string $providerMessageId,
        public readonly array $meta = [],
    ) {
    }
}
