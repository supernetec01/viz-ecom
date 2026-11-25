<?php

use App\Exceptions\FileProcessingFailed;
use App\Exceptions\PdfInspectionFailed;
use App\Services\DocumentSandbox;
use App\Services\InspectCv;
use App\Services\InspectPdf;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Symfony\Component\Process\Process;

require_once __DIR__.'/../Support/CvFixtures.php';
require_once __DIR__.'/../Support/PdfFixtures.php';

beforeEach(function () {
    if (! filter_var(getenv('FILE_PROCESSING_TESTS'), FILTER_VALIDATE_BOOL)) {
        test()->markTestSkipped('Build docker/file-processor and set FILE_PROCESSING_TESTS=1; mandatory in CI.');
    }
    config(['pdf-inspection.enabled' => true]);
});

function documentSandboxPdfSummary(string $path): array
{
    $command = new Process(['docker', 'run', '--rm', '--network', 'none', '--read-only', '--cap-drop', 'ALL', '--security-opt', 'no-new-privileges:true',
        '--memory', '268435456', '--pids-limit', '32', '--mount', 'type=bind,source='.$path.',target=/input.pdf,readonly',
        'viz-ecom-qpdf:local', '--json=2', '--json-key=qpdf', '--json-key=pages', '--json-stream-data=inline', '/input.pdf']);
    $command->setTimeout(10)->mustRun();
    $json = json_decode($command->getOutput(), true, 64, JSON_THROW_ON_ERROR);
    $objects = $json['qpdf'][1];
    $content = [];
    foreach ($json['pages'] as $page) {
        foreach ($page['contents'] as $ref) {
            $content[] = hash('sha256', base64_decode($objects['obj:'.$ref]['stream']['data'], true));
        }
    }

    return ['pages' => count($json['pages']), 'content_hashes' => $content];
}

it('exports a real isolated DOCX preview without OpenAction and preserves page count and content streams', function () {
    $upload = docxUpload();
    $sourceHash = hash_file('sha256', $upload->getPathname());
    $defaultExport = new class extends DocumentSandbox
    {
        protected function exportOptions(): array
        {
            return [...parent::exportOptions(), 'InitialPage' => ['type' => 'long', 'value' => '1']];
        }
    };
    $raw = $defaultExport->convert($upload->getPathname());
    $safe = app(DocumentSandbox::class)->convert($upload->getPathname());
    $rawPath = tempnam(storage_path('framework/testing'), 'raw-pdf-');
    $safePath = tempnam(storage_path('framework/testing'), 'safe-pdf-');
    file_put_contents($rawPath, $raw);
    file_put_contents($safePath, $safe);
    chmod($rawPath, 0444);
    chmod($safePath, 0444);
    try {
        expect(fn () => app(InspectPdf::class)->inspect($rawPath))->toThrow(PdfInspectionFailed::class);
        app(InspectPdf::class)->inspect($safePath);
        expect(documentSandboxPdfSummary($safePath))->toBe(documentSandboxPdfSummary($rawPath));
        expect(documentSandboxPdfSummary($safePath)['pages'])->toBe(1);
        expect(fn () => app(InspectCv::class)->inspect(UploadedFile::fake()->createWithContent('uploaded.pdf', $raw)))->toThrow(ValidationException::class);
        expect(hash_file('sha256', $upload->getPathname()))->toBe($sourceHash);
    } finally {
        chmod($rawPath, 0600);
        chmod($safePath, 0600);
        unlink($rawPath);
        unlink($safePath);
    }
});

it('fails closed when signatures or the processing image are unavailable', function (string $missing) {
    $upload = docxUpload();
    if ($missing === 'signatures') {
        config(['file-processing.definitions_root' => storage_path('app/no-definitions-present')]);
        app(DocumentSandbox::class)->scan($upload->getPathname());
    } else {
        config(['file-processing.image' => 'viz-ecom-file-processor:not-installed']);
        app(DocumentSandbox::class)->convert($upload->getPathname());
    }
})->with(['signatures', 'image'])->throws(FileProcessingFailed::class);

it('kills the whole converter container on timeout and removes its staging', function () {
    config(['file-processing.timeout' => 3.0]);
    $sandbox = new class extends DocumentSandbox
    {
        protected function exec(string $container, array $arguments, float $deadline, array $allowed = [0], int &$exit = 0): string
        {
            return parent::exec($container, $arguments[0] === '/usr/bin/libreoffice' ? ['/bin/sleep', '90'] : $arguments, $deadline, $allowed, $exit);
        }
    };
    $upload = docxUpload();
    expect(fn () => $sandbox->convert($upload->getPathname()))->toThrow(FileProcessingFailed::class);
    $process = new Process(['docker', 'ps', '-aq', '--filter', 'label=io.viz-ecom.file-processing=true']);
    $process->mustRun();
    expect(trim($process->getOutput()))->toBe('');
    expect(glob(config('file-processing.staging_root').'/*'))->toBe([]);
});
