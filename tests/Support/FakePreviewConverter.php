<?php

namespace Tests\Support;

use App\Contracts\PreviewConverter;
use App\Exceptions\FileProcessingFailed;

class FakePreviewConverter implements PreviewConverter
{
    public int $calls = 0;

    public ?FileProcessingFailed $failure = null;

    public function __construct(public string $bytes) {}

    public function convert(string $path): string
    {
        $this->calls++;
        if ($this->failure) {
            throw $this->failure;
        }

        return $this->bytes;
    }
}
