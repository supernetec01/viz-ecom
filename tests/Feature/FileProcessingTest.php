<?php

use App\Contracts\PreviewConverter;
use App\Contracts\VirusScanner;
use App\Exceptions\FileProcessingFailed;
use App\Exceptions\PdfInspectionFailed;
use App\Jobs\ProcessCandidateFile;
use App\Models\CandidateFile;
use App\Services\InspectCv;
use App\Services\InspectPdf;
use App\Services\ProcessStoredFile;
use App\Support\FileReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakePreviewConverter;
use Tests\Support\FakeVirusScanner;

require_once __DIR__.'/../Support/FileProcessingFixtures.php';
require_once __DIR__.'/../Support/PdfFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Storage::fake('candidate-files');
    Cache::flush();
    $this->scanner = new FakeVirusScanner;
    $this->converter = new FakePreviewConverter(cleanPdfBytes());
    app()->instance(VirusScanner::class, $this->scanner);
    app()->instance(PreviewConverter::class, $this->converter);
    $this->pdf = Mockery::mock(InspectPdf::class);
    $this->pdf->shouldReceive('inspect')->andReturn(['elapsed_ms' => 1, 'peak_memory_bytes' => 1])->byDefault();
    app()->instance(InspectPdf::class, $this->pdf);
});

it('creates one checked and scanned DOCX preview and is idempotent on duplicate delivery', function () {
    $file = processingFile();
    $originalHash = hash_file('sha256', Storage::disk('candidate-files')->path($file->path));
    app(ProcessStoredFile::class)->process($file->id, 0);
    $file->refresh();
    expect($file->scan_status)->toBe('clean')->and($file->preview_status)->toBe('ready')->and($file->review_required)->toBeFalse();
    expect($this->scanner->calls)->toBe(2)->and($this->converter->calls)->toBe(1);
    Storage::disk('candidate-files')->assertExists($file->preview_path);
    app(ProcessStoredFile::class)->process($file->id, 0);
    expect($this->scanner->calls)->toBe(2)->and($this->converter->calls)->toBe(1);
    expect(count(Storage::disk('candidate-files')->allFiles()))->toBe(2);
    expect(hash_file('sha256', Storage::disk('candidate-files')->path($file->path)))->toBe($originalHash);
});

it('blocks infected originals immediately without conversion or retry', function () {
    $file = processingFile();
    $this->scanner->failure = new FileProcessingFailed('infected');
    expect(app(ProcessStoredFile::class)->process($file->id, 0))->toBeNull();
    $file->refresh();
    expect($file->scan_status)->toBe('infected')->and($file->review_required)->toBeTrue();
    expect(FileReadiness::for($file)['can_download'])->toBeFalse()->and(FileReadiness::for($file)['can_view'])->toBeFalse();
    app(ProcessStoredFile::class)->process($file->id, 0);
    expect($this->scanner->calls)->toBe(1)->and($this->converter->calls)->toBe(0);
});

it('retries transient scanner errors after one and five minutes and flags error after exactly three actual attempts', function () {
    $file = processingFile();
    $this->scanner->failure = new FileProcessingFailed('scanner_unavailable', true);
    foreach ([60, 300, null] as $index => $delay) {
        $job = (new ProcessCandidateFile($file->id))->withFakeQueueInteractions();
        $job->handle(app(ProcessStoredFile::class));
        $delay === null ? $job->assertNotReleased() : $job->assertReleased($delay);
        expect($file->fresh()->processing_attempts)->toBe($index + 1);
    }
    $file->refresh();
    expect($file->scan_status)->toBe('error')->and($file->review_required)->toBeTrue()->and($file->scan_attempts)->toBe(3);
    app(ProcessStoredFile::class)->process($file->id, 0);
    expect($this->scanner->calls)->toBe(3);
});

it('keeps originals clean and downloadable when conversion permanently fails', function () {
    $file = processingFile();
    $this->converter->failure = new FileProcessingFailed('invalid_document');
    expect(app(ProcessStoredFile::class)->process($file->id, 0))->toBeNull();
    $file->refresh();
    expect($file->scan_status)->toBe('clean')->and($file->preview_status)->toBe('failed')->and($file->review_required)->toBeTrue();
    expect(FileReadiness::for($file))->toBe(['can_download' => true, 'can_view' => false, 'message' => __('files.no_preview')]);
});

it('does not retry corrupt or encrypted Word data', function (string $bytes) {
    $file = processingFile(bytes: $bytes);
    expect(app(ProcessStoredFile::class)->process($file->id, 0))->toBeNull();
    expect($file->fresh()->scan_status)->toBe('error')->and($file->fresh()->processing_attempts)->toBe(1);
    app(ProcessStoredFile::class)->process($file->id, 0);
    expect($this->scanner->calls)->toBe(0)->and($this->converter->calls)->toBe(0);
})->with(['broken DOCX bytes', "\xd0\xcf\x11\xe0\xa1\xb1\x1a\xe1".str_repeat("\0", 1024)]);

it('does not publish a preview rejected by the unchanged PDF inspector', function () {
    $file = processingFile();
    $this->pdf->shouldReceive('inspect')->once()->andThrow(new PdfInspectionFailed('active_content'));
    app(ProcessStoredFile::class)->process($file->id, 0);
    $file->refresh();
    expect($file->scan_status)->toBe('clean')->and($file->preview_status)->toBe('failed')->and($file->preview_path)->toBeNull();
    expect(count(Storage::disk('candidate-files')->allFiles()))->toBe(1);
});

it('retries temporary preview failures without rescanning an unchanged clean original', function () {
    $file = processingFile();
    $this->converter->failure = new FileProcessingFailed('processor_timeout', true);
    foreach ([60, 300, null] as $delay) {
        expect(app(ProcessStoredFile::class)->process($file->id, 0))->toBe($delay);
    }
    expect($this->scanner->calls)->toBe(1)->and($this->converter->calls)->toBe(3);
    expect($file->fresh()->scan_status)->toBe('clean')->and($file->fresh()->preview_status)->toBe('failed');
});

it('locks the entire file when antivirus rejects its generated preview without retry', function () {
    $file = processingFile();
    $this->scanner->onScan = function () {
        if ($this->scanner->calls === 2) {
            throw new FileProcessingFailed('infected');
        }
    };
    expect(app(ProcessStoredFile::class)->process($file->id, 0))->toBeNull();
    expect($file->fresh()->scan_status)->toBe('infected')->and($file->fresh()->review_required)->toBeTrue();
    expect(FileReadiness::for($file->fresh())['can_download'])->toBeFalse();
    app(ProcessStoredFile::class)->process($file->id, 0);
    expect($this->converter->calls)->toBe(1);
});

it('retries a temporary qpdf preview timeout while preserving the clean original', function () {
    $file = processingFile();
    $this->pdf->shouldReceive('inspect')->andThrow(new PdfInspectionFailed('timeout'));
    expect(app(ProcessStoredFile::class)->process($file->id, 0))->toBe(60);
    expect($file->fresh()->scan_status)->toBe('clean')->and($file->fresh()->preview_status)->toBe('pending');
});

it('does not spend an actual attempt while another worker holds the file lock', function () {
    $file = processingFile();
    $lock = Cache::lock('candidate-process:'.$file->id, 240);
    $lock->get();
    $job = (new ProcessCandidateFile($file->id))->withFakeQueueInteractions();
    $job->handle(app(ProcessStoredFile::class));
    $job->assertReleased(60);
    expect($file->fresh()->processing_attempts)->toBe(0)->and($this->scanner->calls)->toBe(0);
    $lock->release();
});

it('ignores deliveries from an older processing version', function () {
    $file = processingFile();
    $file->forceFill(['processing_version' => 1])->save();
    app(ProcessStoredFile::class)->process($file->id, 0);
    expect($file->fresh()->processing_attempts)->toBe(0)->and($this->scanner->calls)->toBe(0);
});

it('blocks every non clean status regardless of preview readiness', function (string $status) {
    $file = new CandidateFile;
    $file->forceFill(['scan_status' => $status, 'preview_status' => 'ready']);
    expect(FileReadiness::for($file)['can_download'])->toBeFalse()->and(FileReadiness::for($file)['can_view'])->toBeFalse();
})->with(['pending', 'infected', 'error']);

it('blocks readiness and retries scanning if original bytes change during virus scanning', function () {
    $file = processingFile();
    $this->scanner->onScan = fn ($path) => file_put_contents($path, 'changed while scanning');
    expect(app(ProcessStoredFile::class)->process($file->id, 0))->toBe(60);
    expect($file->fresh()->scan_status)->toBe('pending')->and($file->fresh()->scanned_sha256)->toBeNull();
});

it('permanently rejects a real password protected Word fixture before any scan or conversion', function () {
    $path = base_path('tests/Fixtures/word/encrypted-standard.docx');
    expect(hash_file('sha256', $path))->toBe('d265dcf02f7d552486229b8c67a627ef3752fe6802b02fd2d2d485fcfbbac5de');
    $file = processingFile(bytes: file_get_contents($path));
    app(ProcessStoredFile::class)->process($file->id, 0);
    expect($file->fresh()->scan_status)->toBe('error')->and($file->fresh()->review_required)->toBeTrue();
    app(ProcessStoredFile::class)->process($file->id, 0);
    expect($file->fresh()->processing_attempts)->toBe(1)->and($this->scanner->calls)->toBe(0)->and($this->converter->calls)->toBe(0);
});

it('makes a real LibreOffice DOCX preview ready only after the unchanged qpdf checker and both virus checks', function () {
    if (! filter_var(getenv('FILE_PROCESSING_TESTS'), FILTER_VALIDATE_BOOL)) {
        test()->markTestSkipped('Requires LibreOffice image; mandatory in CI.');
    }
    app()->forgetInstance(PreviewConverter::class);
    app()->forgetInstance(InspectPdf::class);
    config(['pdf-inspection.enabled' => true]);
    $file = processingFile();
    app(ProcessStoredFile::class)->process($file->id, 0);
    expect($file->fresh()->scan_status)->toBe('clean')->and($file->fresh()->preview_status)->toBe('ready');
    expect($this->scanner->calls)->toBe(2);
    Storage::disk('candidate-files')->assertExists($file->fresh()->preview_path);
});

it('rejects unexpected PDF actions in both uploaded PDFs and previews using the unchanged T23 inspector', function (string $action) {
    if (! filter_var(getenv('PDF_SANDBOX_TESTS'), FILTER_VALIDATE_BOOL)) {
        test()->markTestSkipped('Requires the real qpdf inspector; mandatory in CI.');
    }
    app()->forgetInstance(InspectPdf::class);
    config(['pdf-inspection.enabled' => true]);
    $bytes = pdfFixtureBytes(openAction: $action);
    expect(fn () => app(InspectCv::class)->inspect(UploadedFile::fake()->createWithContent('uploaded.pdf', $bytes)))
        ->toThrow(ValidationException::class);
    $file = processingFile();
    $this->converter->bytes = $bytes;
    app(ProcessStoredFile::class)->process($file->id, 0);
    expect($file->fresh()->scan_status)->toBe('clean')->and($file->fresh()->preview_status)->toBe('failed');
    expect($file->fresh()->preview_path)->toBeNull();
})->with([
    'passive XYZ still rejected when supplied as PDF' => ['[3 0 R /XYZ null null 0]'],
    'JavaScript' => ['<< /S /JavaScript /JS (app.alert\\(1\\)) >>'],
    'URI' => ['<< /S /URI /URI (https://example.org/) >>'],
    'Launch' => ['<< /S /Launch /F (test) >>'],
    'GoToR' => ['<< /S /GoToR /F (outside.pdf) /D [0 /Fit] >>'],
    'GoToE' => ['<< /S /GoToE /F (outside.pdf) /D [0 /Fit] >>'],
    'SubmitForm' => ['<< /S /SubmitForm /F (https://example.org/) >>'],
    'external destination' => ['[(outside.pdf) /XYZ null null 0]'],
]);
