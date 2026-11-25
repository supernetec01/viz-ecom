<?php

namespace App\Contracts;

interface PreviewConverter
{
    public function convert(string $path): string;
}
