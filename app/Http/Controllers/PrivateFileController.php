<?php

namespace App\Http\Controllers;

use App\Policies\ApplicationPolicy;
use App\Services\AccessLimits;
use App\Services\CandidateFileLease;
use App\Services\InspectCv;
use App\Services\RecordAudit;
use App\Support\CandidateFilePaths;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

class PrivateFileController extends Controller
{
    public function __invoke(Request $request, string $id, string $file, string $mode, ApplicationPolicy $policy, RecordAudit $audit): Response
    {
        $leases = [];
        $stream = null;
        try {
            abort_unless(is_string($request->query('actor')) && $request->query('actor') === (string) $request->user()->id, 403);
            $fileId = filter_var($file, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            abort_unless($fileId !== false && in_array($mode, ['inline', 'download'], true), 403);
            $inline = $mode === 'inline';
            $metadata = $policy->fileRecord($request->user(), $id, $fileId, $inline);
            app(AccessLimits::class)->consume($request->user(), 'file', $id, $metadata->getAttribute('application_company_id'), $request);
            $lease = CandidateFileLease::acquire('candidate:'.$metadata->path, false);
            abort_unless($lease !== null, 503);
            $leases[] = $lease;
            $original = CandidateFilePaths::original($metadata);
            abort_unless(is_string($metadata->scanned_sha256) && hash_equals($metadata->sha256, $metadata->scanned_sha256)
                && hash_file('sha256', $original) === $metadata->scanned_sha256, 403);
            $path = $original;
            $mime = $metadata->mime_type;
            if ($inline && $metadata->preview_path !== $metadata->path) {
                $expected = 'previews/'.$metadata->id.'/'.$metadata->scanned_sha256.'.pdf';
                abort_unless($metadata->preview_path === $expected, 403);
                $previewLease = CandidateFileLease::acquire('candidate:'.$expected, false);
                abort_unless($previewLease !== null, 503);
                $leases[] = $previewLease;
                $root = realpath(Storage::disk('candidate-files')->path(''));
                $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, Storage::disk('candidate-files')->path($expected));
                abort_unless($root !== false && realpath($path) === $path && ! is_link($path) && str_starts_with($path, $root.DIRECTORY_SEPARATOR), 403);
                $mime = 'application/pdf';
            }
            abort_unless(in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true)
                && (! $inline || $mime !== 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'), 403);
            $stream = fopen($path, 'rb');
            abort_unless(is_resource($stream), 403);
            $stat = fstat($stream);
            abort_unless($stat !== false && $stat['size'] > 0 && $stat['size'] <= InspectCv::MAX_BYTES, 403);
            $audit->record($request->user(), $inline ? 'application.file_viewed' : 'application.file_downloaded', $id, $metadata->getAttribute('application_company_id'), $request, ['file_id' => $fileId]);
            $policy->fileRecord($request->user(), $id, $fileId, $inline);
            $extension = match ($mime) {
                'application/pdf' => 'pdf', 'image/jpeg' => 'jpg', 'image/png' => 'png', default => 'docx',
            };
            $response = response()->stream(function () use ($stream, $leases): void {
                try {
                    while (! feof($stream)) {
                        $chunk = fread($stream, 65536);
                        if ($chunk === false) {
                            break;
                        }
                        echo $chunk;
                    }
                } finally {
                    fclose($stream);
                    foreach ($leases as $lease) {
                        $lease->release();
                    }
                }
            }, 200, ['Content-Type' => $mime, 'Content-Length' => (string) $stat['size'],
                'Cache-Control' => 'no-store, private', 'X-Robots-Tag' => 'noindex, nofollow', 'Accept-Ranges' => 'none']);
            // Neutral output names avoid contact data in Content-Disposition.
            $response->headers->set('Content-Disposition', $response->headers->makeDisposition(
                $inline ? ResponseHeaderBag::DISPOSITION_INLINE : ResponseHeaderBag::DISPOSITION_ATTACHMENT, 'file-'.$fileId.'.'.$extension));

            return $response;
        } catch (Throwable $exception) {
            if (is_resource($stream)) {
                fclose($stream);
            }
            foreach ($leases as $lease) {
                $lease->release();
            }
            if ($exception instanceof HttpExceptionInterface) {
                throw $exception;
            }
            abort(503, __('applications.detail_unavailable'));
        }
    }
}
