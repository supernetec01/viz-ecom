<?php

use App\Services\InspectCv;
use App\Services\StoreCandidateFile;
use App\Support\StoredCandidateFile;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/CvFixtures.php';

uses(RefreshDatabase::class);

it('rejects an executable renamed to PDF using actual bytes rather than the client MIME', function () {
    $file = UploadedFile::fake()->createWithContent('cv.pdf', "MZ\x90\0".str_repeat('X', 1024));
    app(InspectCv::class)->inspect($file);
})->throws(ValidationException::class);

it('rejects an executable renamed to DOCX', function () {
    app(InspectCv::class)->inspect(UploadedFile::fake()->createWithContent('cv.docx', "MZ\x90\0".str_repeat('X', 1024)));
})->throws(ValidationException::class);

it('rejects a file that is ten MiB plus one byte by its real on disk size', function () {
    app(InspectCv::class)->inspect(UploadedFile::fake()->createWithContent('cv.docx', str_repeat('X', InspectCv::MAX_BYTES + 1)));
})->throws(ValidationException::class);

it('rejects legacy DOC with a friendly conversion message', function () {
    try {
        app(InspectCv::class)->inspect(UploadedFile::fake()->createWithContent('cv.doc', "\xD0\xCF\x11\xE0\xA1\xB1\x1A\xE1".str_repeat("\0", 1024)));
        test()->fail('Legacy DOC must not be accepted.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['cv'])->toBe([__('applications.legacy_doc')]);
    }
});

it('accepts a clean DOCX after inspecting its actual package structure', function () {
    $result = app(InspectCv::class)->inspect(docxUpload());
    expect($result['extension'])->toBe('docx');
    expect($result['mime_type'])->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect($result['size'])->toBeGreaterThan(0);
});

it('rejects DOCX containing vbaProject', function () {
    app(InspectCv::class)->inspect(docxUpload(['word/vbaProject.bin' => 'harmless macro placeholder']));
})->throws(ValidationException::class);

it('rejects DOCM even when renamed content looks like a plain DOCX', function () {
    app(InspectCv::class)->inspect(docxUpload(name: 'cv.docm'));
})->throws(ValidationException::class);

it('rejects a remote template relationship', function () {
    app(InspectCv::class)->inspect(docxUpload(relationships: '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/attachedTemplate" Target="https://example.org/template.dotm" TargetMode="External"/></Relationships>'));
})->throws(ValidationException::class);

it('rejects an external hyperlink relationship without fetching it', function () {
    app(InspectCv::class)->inspect(docxUpload(relationships: '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/hyperlink" Target="https://example.org/" TargetMode="External"/></Relationships>'));
})->throws(ValidationException::class);

it('rejects embedded OLE objects', function () {
    app(InspectCv::class)->inspect(docxUpload(['word/embeddings/oleObject1.bin' => 'placeholder']));
})->throws(ValidationException::class);

it('rejects ActiveX controls', function () {
    app(InspectCv::class)->inspect(docxUpload(['word/activeX/activeX1.xml' => '<control/>']));
})->throws(ValidationException::class);

it('rejects dangerous archive member paths', function (string $path) {
    app(InspectCv::class)->inspect(docxUpload([$path => 'unsafe path placeholder']));
})->with(['../escape.txt', '/absolute.txt', 'word/../../escape.txt', 'C:/escape.txt', 'word\\escape.txt', 'word/%2e%2e/escape.txt'])->throws(ValidationException::class);

it('rejects a DOCX compression bomb before expanding it', function () {
    app(InspectCv::class)->inspect(docxUpload(['word/bomb.xml' => str_repeat('A', 2000000)]));
})->throws(ValidationException::class);

it('rejects an abnormal number of zip entries', function () {
    $entries = [];
    for ($i = 0; $i < 513; $i++) {
        $entries['extra/'.$i.'.txt'] = 'small';
    }
    app(InspectCv::class)->inspect(docxUpload($entries));
})->throws(ValidationException::class);

it('rejects XML external entity declarations', function () {
    app(InspectCv::class)->inspect(docxUpload(['word/document.xml' => '<!DOCTYPE x [<!ENTITY test SYSTEM "file:///etc/passwd">]><x>&test;</x>']));
})->throws(ValidationException::class);

it('rejects empty required document XML with a validation error', function () {
    app(InspectCv::class)->inspect(docxUpload(['word/document.xml' => '']));
})->throws(ValidationException::class);

it('does not accept any PDF until a deep inspector has been approved and implemented', function () {
    try {
        app(InspectCv::class)->inspect(UploadedFile::fake()->createWithContent('cv.pdf', cleanPdfBytes()));
        test()->fail('PDF inspection is not implemented yet.');
    } catch (ValidationException $exception) {
        expect($exception->errors()['cv'])->toBe([__('applications.pdf_rejected')]);
    }
});

it('stores checked DOCX under private company job and application directories with a UUID name and actual hash', function () {
    Storage::fake('candidate-files');
    $job = managedJob(jobManager());
    $applicationId = (string) Str::ulid();
    $upload = docxUpload(name: 'cv-original.docx');
    $stored = app(StoreCandidateFile::class)->storeCv($upload, $job, $applicationId);
    expect($stored->path())->toStartWith('cv/'.$job->company_id.'/'.$job->id.'/'.$applicationId.'/');
    expect(Str::isUuid(pathinfo($stored->path(), PATHINFO_FILENAME)))->toBeTrue();
    expect($stored->metadata())->toMatchArray(['original_name' => 'cv-original.docx', 'scan_status' => 'pending', 'sha256' => hash_file('sha256', $upload->getPathname())]);
    Storage::disk('candidate-files')->assertExists($stored->path());
    // Windows does not report Unix mode bits; CI/Linux verifies actual 0600 visibility.
    if (PHP_OS_FAMILY !== 'Windows') {
        expect(Storage::disk('candidate-files')->getVisibility($stored->path()))->toBe('private');
    }
    expect(config('filesystems.disks.candidate-files.visibility'))->toBe('private');
    expect(json_encode($stored))->toBe('{}');
    expect(config('filesystems.disks.candidate-files.serve'))->toBeFalse();
    expect(str_starts_with(Storage::disk('candidate-files')->path(''), public_path()))->toBeFalse();
    foreach (['/storage/', '/storage/candidate-files/', '/candidate-files/'] as $prefix) {
        // Laravel's unrelated signed local-disk route may reject with 403 rather than 404.
        expect($this->get($prefix.$stored->path())->getStatusCode())->toBeIn([403, 404]);
    }
    app(StoreCandidateFile::class)->discard($stored);
    Storage::disk('candidate-files')->assertMissing($stored->path());
});

it('never writes a rejected upload to the private disk', function () {
    Storage::fake('candidate-files');
    try {
        app(StoreCandidateFile::class)->storeCv(docxUpload(['word/vbaProject.bin' => 'blocked']), managedJob(jobManager()), (string) Str::ulid());
        test()->fail('Unsafe file must be rejected.');
    } catch (ValidationException) {
        expect(Storage::disk('candidate-files')->allFiles())->toBe([]);
    }
});

it('does not allow cleanup paths supplied from outside a created candidate file context', function () {
    Storage::fake('candidate-files');
    app(StoreCandidateFile::class)->discard(new StoredCandidateFile(['path' => '../other-file.docx']));
})->throws(RuntimeException::class, 'Invalid candidate cleanup path.');
