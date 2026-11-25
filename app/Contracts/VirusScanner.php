<?php

namespace App\Contracts;

interface VirusScanner
{
    public function scan(string $path): void;
}
