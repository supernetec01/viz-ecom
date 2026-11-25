<?php

namespace App\Services;

use App\Exceptions\CandidateCleanupFailed;
use App\Policies\ApplicationPolicy;
use FilesystemIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Throwable;

class CleanCandidateFiles
{
    public function run(bool $force): array
    {
        $report = ['mode' => $force ? 'force' : 'dry-run', 'scanned' => 0, 'candidates' => 0, 'deleted' => 0,
            'skipped' => [], 'safety_stops' => [], 'items' => [], 'unattached_metadata_count' => 0, 'unattached_metadata_ids' => []];
        try {
            return $this->inspectAndClean($force, $report);
        } catch (Throwable) {
            throw new CandidateCleanupFailed($report);
        }
    }

    private function inspectAndClean(bool $force, array &$report): array
    {
        if ($force && app()->isProduction() && ! config('candidate-cleanup.production_force_enabled')) {
            $report['safety_stops'][] = 'production_force_disabled';

            return $report;
        }
        // A failed query aborts the run. It must never be interpreted as zero references.
        $metadataCount = DB::table('files')->count();
        $ids = app(ApplicationPolicy::class)->unattachedFileMetadataIds();
        $report['unattached_metadata_count'] = count($ids);
        $report['unattached_metadata_ids'] = $force ? [] : $ids;
        $roots = ['candidate' => Storage::disk('candidate-files')->path(''),
            'pdf-staging' => config('candidate-cleanup.staging_root')];
        $plan = [];
        foreach ($roots as $kind => $configured) {
            if (preg_match('~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $configured)) {
                throw new RuntimeException('Unsafe cleanup root.');
            }
            if (! is_dir($configured)) {
                continue;
            }
            $root = realpath($configured);
            if ($root === false || is_link(rtrim($configured, '/\\'))) {
                throw new RuntimeException('Unsafe cleanup root.');
            }
            $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $entry) {
                if ($entry->isLink()) {
                    $this->skip($report, 'symlink');

                    continue;
                }
                if (! $entry->isFile()) {
                    continue;
                }
                $report['scanned']++;
                $path = $entry->getPathname();
                $relative = str_replace('\\', '/', substr($path, strlen($root) + 1));
                if (! $this->inside($root, $path, $relative)) {
                    $this->skip($report, 'outside_root');

                    continue;
                }
                if (! $this->old($path)) {
                    $this->skip($report, 'young');

                    continue;
                }
                if ($this->referenced($kind, $relative)) {
                    $this->skip($report, 'referenced');

                    continue;
                }
                $key = $kind.':'.($kind === 'pdf-staging' ? explode('/', $relative)[0] : $relative);
                $lease = CandidateFileLease::acquire($key, false);
                if ($lease === null) {
                    $this->skip($report, 'active');

                    continue;
                }
                $lease->release();
                $plan[] = compact('root', 'path', 'relative', 'kind', 'key');
                $report['items'][] = ['root' => $kind, 'key' => hash('sha256', $relative), 'modified_at' => gmdate('c', filemtime($path))];
            }
        }
        $report['candidates'] = count($plan);
        if ($report['scanned'] > 0 && count($plan) / $report['scanned'] > (float) config('candidate-cleanup.max_orphan_ratio')) {
            $report['safety_stops'][] = 'orphan_ratio';
        }
        if ($metadataCount === 0 && $report['scanned'] > (int) config('candidate-cleanup.empty_metadata_limit')) {
            $report['safety_stops'][] = 'empty_metadata';
        }
        if (! $force || $report['safety_stops'] !== []) {
            return $report;
        }
        foreach ($plan as $item) {
            $lease = CandidateFileLease::acquire($item['key'], false);
            if ($lease === null) {
                $this->skip($report, 'active');

                continue;
            }
            try {
                clearstatcache(true, $item['path']);
                if (! $this->inside($item['root'], $item['path'], $item['relative']) || ! $this->old($item['path'])) {
                    $this->skip($report, 'changed');

                    continue;
                }
                if ($this->referenced($item['kind'], $item['relative'])) {
                    $this->skip($report, 'referenced');

                    continue;
                }
                // Recheck mtime immediately before unlink, after the database round trip.
                clearstatcache(true, $item['path']);
                if (! $this->inside($item['root'], $item['path'], $item['relative']) || ! $this->old($item['path'])) {
                    $this->skip($report, 'changed');

                    continue;
                }
                // PdfSandbox uses 0444 staging; Windows requires clearing its read-only bit.
                if ($item['kind'] === 'pdf-staging' && ! chmod($item['path'], 0600)) {
                    throw new RuntimeException('Candidate staging cleanup failed.');
                }
                if (! unlink($item['path'])) {
                    throw new RuntimeException('Candidate cleanup unlink failed.');
                }
                $report['deleted']++;
            } finally {
                $lease->release();
            }
        }

        return $report;
    }

    private function referenced(string $kind, string $relative): bool
    {
        return DB::table('files')->where('path', $relative)->orWhere('preview_path', $relative)->exists();
    }

    private function inside(string $root, string $path, string $relative): bool
    {
        if (preg_match('~(?:^|/)\.\.(?:/|$)~', $relative) || is_link($path)) {
            return false;
        }
        $resolved = realpath($path);

        return $resolved !== false && str_starts_with($resolved, $root.DIRECTORY_SEPARATOR) && $resolved === $path;
    }

    private function old(string $path): bool
    {
        $modified = filemtime($path);

        return $modified !== false && $modified <= now()->subDay()->timestamp;
    }

    private function skip(array &$report, string $reason): void
    {
        $report['skipped'][$reason] = ($report['skipped'][$reason] ?? 0) + 1;
    }
}
