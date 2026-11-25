<?php

namespace App\Events;

final readonly class FileProcessingRetryRequested
{
    public function __construct(public int $actorId, public int $fileId, public string $stage, public string $occurredAt) {}
}
