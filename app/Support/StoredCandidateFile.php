<?php

namespace App\Support;

final readonly class StoredCandidateFile
{
    public function __construct(private array $metadata) {}

    public function path(): string
    {
        return $this->metadata['path'];
    }

    public function metadata(): array
    {
        return $this->metadata;
    }
}
