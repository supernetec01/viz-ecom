<?php

namespace Tests\Support;

use App\Contracts\VirusScanner;
use App\Exceptions\FileProcessingFailed;
use Closure;

class FakeVirusScanner implements VirusScanner
{
    public int $calls = 0;

    public ?FileProcessingFailed $failure = null;

    public ?Closure $onScan = null;

    public function scan(string $path): void
    {
        $this->calls++;
        if ($this->onScan) {
            ($this->onScan)($path);
        }
        if ($this->failure) {
            throw $this->failure;
        }
    }
}
