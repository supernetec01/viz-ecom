<?php

use App\Enums\JobStatus;
use App\Services\InspectPortfolio;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/../Support/PortfolioFixtures.php';
require_once __DIR__.'/../Support/CvFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
    Storage::fake('candidate-files');
});

function portfolioFormData($test, $job, array $overrides = []): array
{
    $response = $test->get(route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]))->assertOk();
    preg_match('/name="submission_token" value="([a-f0-9]+)"/', $response->getContent(), $match);
    $test->withCookie(config('session.cookie'), session()->getId());

    return array_replace(['full_name' => 'Ứng viên portfolio mẫu', 'phone' => '0987654321', 'consent' => '1',
        'submission_token' => $match[1], 'cf-turnstile-response' => bin2hex(random_bytes(16))], $overrides);
}

it('denies portfolio uploads to paused expired and closed jobs before storing files', function (JobStatus $state) {
    $job = publishManagedJob(managedJob(jobManager()));
    $job->transitionTo($state);
    $this->post(route('applications.store', ['id' => $job->id]), ['portfolio_file' => portfolioImage()])->assertForbidden();
    expect(Storage::disk('candidate-files')->allFiles())->toBe([]);
    Http::assertNothingSent();
})->with([JobStatus::Paused, JobStatus::Expired, JobStatus::Closed]);

it('accepts optional empty or HTTP HTTPS portfolio links without fetching them', function (?string $url) {
    $job = publishManagedJob(managedJob(jobManager()));
    $this->post(route('applications.store', ['id' => $job->id]), portfolioFormData($this, $job, ['portfolio_url' => $url]))->assertSessionHasNoErrors();
    expect(DB::table('applications')->value('portfolio_url'))->toBe($url);
    expect(DB::table('applications')->value('portfolio_file_id'))->toBeNull();
    Http::assertSentCount(1);
})->with([null, 'http://example.org/work', 'https://example.org/work']);

it('rejects non web portfolio protocols', function (string $url) {
    $job = publishManagedJob(managedJob(jobManager()));
    $this->post(route('applications.store', ['id' => $job->id]), portfolioFormData($this, $job, ['portfolio_url' => $url]))->assertSessionHasErrors('portfolio_url');
    expect(DB::table('applications')->count())->toBe(0);
    Http::assertNothingSent();
})->with(['javascript:alert(1)', 'data:text/plain,x', 'file:///tmp/file', 'ftp://example.org/work', '//example.org/work']);

it('stores a real JPG or PNG portfolio privately by UUID with pending metadata', function (string $type) {
    $job = publishManagedJob(managedJob(jobManager()));
    $upload = portfolioImage($type);
    $hash = hash_file('sha256', $upload->getPathname());
    $this->post(route('applications.store', ['id' => $job->id]), portfolioFormData($this, $job, ['portfolio_file' => $upload]))->assertSessionHasNoErrors();
    $application = DB::table('applications')->first();
    $file = DB::table('files')->where('id', $application->portfolio_file_id)->first();
    expect($file->path)->toStartWith('portfolio/'.$job->company_id.'/'.$job->id.'/'.$application->id.'/');
    expect($file->path)->toMatch('/[a-f0-9-]{36}\.(jpg|png)$/');
    expect($file->sha256)->toBe($hash)->and($file->scan_status)->toBe('pending');
    expect($application->portfolio_url)->toBeNull();
    Storage::disk('candidate-files')->assertExists($file->path);
    // NTFS does not expose Unix mode bits; CI/Linux verifies actual private permissions.
    if (PHP_OS_FAMILY !== 'Windows') {
        expect(Storage::disk('candidate-files')->getVisibility($file->path))->toBe('private');
    }
    expect(config('filesystems.disks.candidate-files.visibility'))->toBe('private');
    expect(config('filesystems.disks.candidate-files.serve'))->toBeFalse();
    expect($this->get('/storage/'.$file->path)->status())->toBeIn([403, 404]);
})->with(['jpg', 'png']);

it('rejects simultaneous portfolio link and file without writing either file or application', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $this->post(route('applications.store', ['id' => $job->id]), portfolioFormData($this, $job, [
        'portfolio_url' => 'https://example.org/work', 'portfolio_file' => portfolioImage(), 'cv' => docxUpload(),
    ]))->assertSessionHasErrors('portfolio_file');
    expect(DB::table('applications')->count())->toBe(0)->and(Storage::disk('candidate-files')->allFiles())->toBe([]);
});

it('rejects executables renamed to each portfolio extension using actual content', function (string $extension) {
    app(InspectPortfolio::class)->inspect(UploadedFile::fake()->createWithContent('work.'.$extension, "MZ\x90\0".str_repeat('X', 1024)));
})->with(['pdf', 'jpg', 'png'])->throws(ValidationException::class);

it('rejects oversized files by their real bytes rather than the reported upload size', function () {
    app(InspectPortfolio::class)->inspect(UploadedFile::fake()->createWithContent('work.png', str_repeat('X', 10485761)));
})->throws(ValidationException::class);

it('rejects malformed images trailing payload and excessive dimensions', function (string $case) {
    $bytes = portfolioPngBytes();
    if ($case === 'truncated') {
        $bytes = substr($bytes, 0, -12);
    } elseif ($case === 'trailing') {
        $bytes .= '<?php harmless_test();';
    } elseif ($case === 'crc') {
        $bytes[48] = chr(ord($bytes[48]) ^ 1);
    } else {
        $bytes = substr_replace($bytes, pack('N', 999999), 16, 4);
    }
    app(InspectPortfolio::class)->inspect(UploadedFile::fake()->createWithContent('work.png', $bytes));
})->with(['truncated', 'trailing', 'crc', 'dimensions'])->throws(ValidationException::class);

it('removes both newly stored CV and portfolio and rolls back both metadata records on database failure', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $data = portfolioFormData($this, $job, ['cv' => docxUpload(), 'portfolio_file' => portfolioImage()]);
    DB::listen(function (QueryExecuted $query) {
        if (str_starts_with($query->sql, 'insert into "applications"')) {
            throw new RuntimeException('Synthetic database failure');
        }
    });
    $this->post(route('applications.store', ['id' => $job->id]), $data)->assertSessionHasErrors('application');
    expect(DB::table('files')->count())->toBe(0)->and(DB::table('applications')->count())->toBe(0);
    expect(Storage::disk('candidate-files')->allFiles())->toBe([]);
});

it('removes a stored CV if portfolio inspection fails before application insertion', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $data = portfolioFormData($this, $job, ['cv' => docxUpload(), 'portfolio_file' => UploadedFile::fake()->createWithContent('invalid.jpg', 'not an image')]);
    $this->post(route('applications.store', ['id' => $job->id]), $data)->assertSessionHasErrors('portfolio_file');
    expect(DB::table('files')->count())->toBe(0)->and(DB::table('applications')->count())->toBe(0);
    expect(Storage::disk('candidate-files')->allFiles())->toBe([]);
});

it('does not accept portfolio PDFs when inspection is disabled', function () {
    config(['pdf-inspection.enabled' => false]);
    app(InspectPortfolio::class)->inspect(UploadedFile::fake()->createWithContent('work.pdf', cleanPdfBytes()));
})->throws(ValidationException::class);

it('uses the real PDF inspector for portfolio submission including compressed actions and encryption', function (string $fixture, bool $allowed) {
    if (! filter_var(getenv('PDF_SANDBOX_TESTS'), FILTER_VALIDATE_BOOL)) {
        test()->markTestSkipped('Requires T23 Docker inspector; mandatory in CI.');
    }
    config(['pdf-inspection.enabled' => true]);
    $job = publishManagedJob(managedJob(jobManager()));
    $bytes = file_get_contents(base_path('tests/Fixtures/pdf/generated/'.$fixture.'.pdf'));
    $data = portfolioFormData($this, $job, ['portfolio_file' => UploadedFile::fake()->createWithContent('work.pdf', $bytes)]);
    $response = $this->post(route('applications.store', ['id' => $job->id]), $data);
    if ($allowed) {
        $response->assertSessionHasNoErrors();
        $file = DB::table('files')->first();
        expect($file->mime_type)->toBe('application/pdf')->and($file->sha256)->toBe(hash('sha256', $bytes));
    } else {
        $response->assertSessionHasErrors('portfolio_file');
        expect(DB::table('files')->count())->toBe(0)->and(DB::table('applications')->count())->toBe(0);
        expect(Storage::disk('candidate-files')->allFiles())->toBe([]);
    }
})->with([['clean-object-stream', true], ['javascript-object-stream', false], ['encrypted', false]]);

it('accepts exactly ten MiB of a structurally valid PNG without trusting a fake reported size', function () {
    $bytes = portfolioPngBytes();
    $padding = 'test'."\0".str_repeat('P', 10485760 - strlen($bytes) - 12 - 5);
    $chunk = pack('N', strlen($padding)).'tEXt'.$padding.hash('crc32b', 'tEXt'.$padding, true);
    $bytes = substr($bytes, 0, -12).$chunk.substr($bytes, -12);
    $checked = app(InspectPortfolio::class)->inspect(UploadedFile::fake()->createWithContent('work.png', $bytes));
    expect($checked['size'])->toBe(10485760);
});

it('rejects malformed JPEG boundaries and trailing data', function (string $case) {
    $bytes = portfolioJpegBytes();
    $bytes = $case === 'truncated' ? substr($bytes, 0, -2) : $bytes.'trailing bytes';
    app(InspectPortfolio::class)->inspect(UploadedFile::fake()->createWithContent('work.jpg', $bytes));
})->with(['truncated', 'trailing'])->throws(ValidationException::class);

it('returns a friendly Vietnamese 413 when the whole request exceeds the PHP upload limit', function () {
    $response = $this->call('POST', '/ung-tuyen/1', [], [], [], ['CONTENT_LENGTH' => 1000000000])
        ->assertStatus(413)->assertSee(__('applications.upload_too_large'))->assertDontSee('PostTooLargeException');
    $header = $response->headers->get('Content-Security-Policy');
    expect($header)->toContain("script-src 'none'")->not->toContain('unsafe-eval', 'unsafe-inline', 'challenges.cloudflare.com');
    Http::assertNothingSent();
});

it('reports a failed PHP temporary upload in Vietnamese without saving a record', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $file = portfolioImage();
    $failed = new UploadedFile($file->getPathname(), 'work.png', 'image/png', UPLOAD_ERR_NO_TMP_DIR, true);
    $this->post(route('applications.store', ['id' => $job->id]), portfolioFormData($this, $job, ['portfolio_file' => $failed]))
        ->assertSessionHasErrors(['portfolio_file' => __('validation.uploaded', ['attribute' => __('applications.fields.portfolio_file')])]);
    expect(DB::table('applications')->count())->toBe(0);
});
