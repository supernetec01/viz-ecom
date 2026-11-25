<?php

namespace App\Services;

use RuntimeException;

final class CandidateFileLease
{
    /** @param resource $handle */
    private function __construct(private $handle) {}

    public static function acquire(string $key, bool $blocking = true): ?self
    {
        $root = config('candidate-cleanup.lock_root');
        if (is_link($root) || (! is_dir($root) && ! mkdir($root, 0700, true))) {
            throw new RuntimeException('Candidate file lock unavailable.');
        }
        $path = $root.'/'.hash('sha256', $key).'.lock';
        if (is_link($path)) {
            throw new RuntimeException('Invalid candidate file lock.');
        }
        $handle = fopen($path, 'c');
        if ($handle === false) {
            throw new RuntimeException('Candidate file lock unavailable.');
        }
        chmod($path, 0600);
        if (! flock($handle, LOCK_EX | ($blocking ? 0 : LOCK_NB))) {
            fclose($handle);

            return null;
        }

        return new self($handle);
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
