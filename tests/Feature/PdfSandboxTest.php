<?php

use App\Exceptions\PdfInspectionFailed;
use App\Services\InspectPdf;
use Symfony\Component\Process\Process;
use Tests\Support\PdfFailureProbe;

require_once __DIR__.'/../Support/CvFixtures.php';
require_once __DIR__.'/../Support/PdfFixtures.php';

beforeEach(function () {
    if (! filter_var(getenv('PDF_SANDBOX_TESTS'), FILTER_VALIDATE_BOOL)) {
        test()->markTestSkipped('Run with PDF_SANDBOX_TESTS=1 after building docker/pdf-inspector; mandatory in CI.');
    }
    config(['pdf-inspection.enabled' => true]);
    $this->pdfPath = tempnam(storage_path('framework/testing'), 'pdf-');
    file_put_contents($this->pdfPath, cleanPdfBytes());
});

afterEach(function () {
    if (isset($this->pdfPath) && is_file($this->pdfPath)) {
        unlink($this->pdfPath);
    }
});

it('accepts a real clean PDF with verified network isolation and memory limits without changing original bytes', function () {
    $hash = hash_file('sha256', $this->pdfPath);
    $result = app(InspectPdf::class)->inspect($this->pdfPath);
    expect($result['elapsed_ms'])->toBeGreaterThan(0)->toBeLessThan(8000);
    expect($result['peak_memory_bytes'])->toBeGreaterThan(0)->toBeLessThanOrEqual(268435456);
    expect(hash_file('sha256', $this->pdfPath))->toBe($hash);
});

it('rejects a hung missing or excessive output qpdf process and removes its container and scratch', function (string $mode) {
    $probe = new PdfFailureProbe($mode);
    $failed = false;
    try {
        $probe->inspect($this->pdfPath);
    } catch (PdfInspectionFailed) {
        $failed = true;
    }
    expect($failed)->toBeTrue();
    $process = new Process(['docker', 'ps', '-aq', '--filter', 'label=io.viz-ecom.pdf-inspection=true']);
    $process->mustRun();
    expect(trim($process->getOutput()))->toBe('');
    expect(glob(storage_path('app/pdf-inspection/*')))->toBe([]);
})->with(['hold', 'missing', 'output']);

it('proves the container memory limit kills an allocating process and rejects the file', function () {
    $probe = new PdfFailureProbe('memory');
    try {
        $probe->inspect($this->pdfPath);
        test()->fail('Memory exhaustion must fail closed.');
    } catch (PdfInspectionFailed) {
        expect($probe->observedOom)->toBeTrue();
    }
});

it('rejects a damaged PDF and missing inspection image', function (string $case) {
    if ($case === 'damaged') {
        file_put_contents($this->pdfPath, "%PDF-1.7\ninvalid");
    } else {
        config(['pdf-inspection.image' => 'viz-ecom-qpdf:not-installed']);
    }
    app(InspectPdf::class)->inspect($this->pdfPath);
})->with(['damaged', 'image'])->throws(PdfInspectionFailed::class);

it('accepts a real PDF with generated compressed object streams', function () {
    $path = base_path('tests/Fixtures/pdf/generated/clean-object-stream.pdf');
    expect(file_get_contents($path))->toContain('/ObjStm');
    expect(app(InspectPdf::class)->inspect($path)['peak_memory_bytes'])->toBeGreaterThan(0);
});

it('rejects JavaScript hidden in an actual compressed object stream', function () {
    $path = base_path('tests/Fixtures/pdf/generated/javascript-object-stream.pdf');
    expect(file_get_contents($path))->toContain('/ObjStm')->not->toContain('/JavaScript', '/OpenAction');
    app(InspectPdf::class)->inspect($path);
})->throws(PdfInspectionFailed::class);

it('rejects actual encrypted PDFs including those opening without a password', function (string $filename) {
    app(InspectPdf::class)->inspect(base_path('tests/Fixtures/pdf/generated/'.$filename));
})->with(['encrypted.pdf', 'encrypted-empty-password.pdf'])->throws(PdfInspectionFailed::class);

it('rejects a real PDF exceeding the object count budget', function () {
    app(InspectPdf::class)->inspect(base_path('tests/Fixtures/pdf/generated/many-objects.pdf'));
})->throws(PdfInspectionFailed::class);

it('accepts upstream Word print Chromium and Google Docs corpus using the same policy', function (string $name) {
    $path = base_path('.runtime/pdf-corpus/'.$name.'.pdf');
    expect(is_file($path))->toBeTrue();
    app(InspectPdf::class)->inspect($path);
})->with(['word-print', 'chromium', 'google-docs']);

it('refuses otherwise readable upstream exports containing prohibited OpenAction', function (string $name) {
    app(InspectPdf::class)->inspect(base_path('.runtime/pdf-corpus/'.$name.'.pdf'));
})->with(['word-2016', 'libreoffice'])->throws(PdfInspectionFailed::class);

it('accepts exactly ten MiB of actual image PDF through the private inspector', function () {
    file_put_contents($this->pdfPath, pdfFixtureBytes(image: true));
    expect(filesize($this->pdfPath))->toBe(10485760);
    app(InspectPdf::class)->inspect($this->pdfPath);
});
