<?php

namespace App\Support;

final readonly class ContentScanResult
{
    /** @param list<array{field: string, rule: string, value?: string}> $findings */
    public function __construct(public array $findings) {}

    public function requiresReview(): bool
    {
        return $this->findings !== [];
    }
}
