<?php

namespace App\Support;

use App\Exceptions\FileProcessingFailed;
use App\Models\CandidateFile;
use App\Services\InspectCv;
use Illuminate\Support\Facades\Storage;

final class CandidateFilePaths
{
    public static function original(CandidateFile $file): string
    {
        if (! preg_match('~\A(?:cv|portfolio)/[1-9][0-9]*/[1-9][0-9]*/[0-7][0-9A-HJKMNP-TV-Z]{25}/[a-f0-9-]{36}\.(docx|pdf|jpg|png)\z~D', $file->path)) {
            throw new FileProcessingFailed('invalid_path');
        }
        $root = realpath(Storage::disk('candidate-files')->path(''));
        $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, Storage::disk('candidate-files')->path($file->path));
        $resolved = realpath($path);
        if ($root === false || $resolved === false || is_link($path) || $resolved !== $path || ! str_starts_with($resolved, $root.DIRECTORY_SEPARATOR)) {
            throw new FileProcessingFailed('file_missing');
        }
        $size = filesize($resolved);
        if ($size === false || $size < 1 || $size > InspectCv::MAX_BYTES) {
            throw new FileProcessingFailed('invalid_file');
        }

        return $resolved;
    }
}
