<?php

namespace App\Support;

use App\Services\CandidateFileLease;

final readonly class StoredCandidateFile
{
    public function __construct(private array $metadata, private ?CandidateFileLease $lease = null) {}

    public function path(): string
    {
        return $this->metadata['path'];
    }

    public function metadata(): array
    {
        return $this->metadata;
    }

    public function __destruct()
    {
        $this->lease?->release();
    }
}
