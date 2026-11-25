<?php

use App\Enums\JobStatus;
use App\Services\StoreCandidateFile;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

require_once __DIR__.'/../Support/CvFixtures.php';
require_once __DIR__.'/../Support/PdfFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake(['challenges.cloudflare.com/*' => Http::response(['success' => true])]);
    Storage::fake('candidate-files');
});

it('denies direct submission to each non accepting public state before captcha or file storage', function (JobStatus $state) {
    $job = publishManagedJob(managedJob(jobManager()));
    $job->transitionTo($state);
    $this->post(route('applications.store', ['id' => $job->id]), ['cv' => docxUpload()])->assertForbidden();
    Http::assertNothingSent();
    expect(DB::table('applications')->count())->toBe(0);
    expect(Storage::disk('candidate-files')->allFiles())->toBe([]);
})->with([JobStatus::Paused, JobStatus::Expired, JobStatus::Closed]);

function applicationFormData($test, $job, array $overrides = []): array
{
    $response = $test->get(route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]))->assertOk();
    preg_match('/name="submission_token" value="([a-f0-9]+)"/', $response->getContent(), $match);
    $test->withCookie(config('session.cookie'), session()->getId());

    return array_replace(['full_name' => 'Ứng viên mẫu', 'phone' => '0912345678', 'consent' => '1',
        'submission_token' => $match[1] ?? '', 'cf-turnstile-response' => bin2hex(random_bytes(16))], $overrides);
}

it('accepts a guest without CV and normalizes equivalent phone formats', function (string $phone) {
    $job = publishManagedJob(managedJob(jobManager()));
    $data = applicationFormData($this, $job, ['phone' => $phone, 'company_id' => 99999, 'source' => 'forged']);
    $this->post(route('applications.store', ['id' => $job->id]), $data)->assertRedirect();
    $row = DB::table('applications')->first();
    expect($row->phone_normalized)->toBe('0912345678')->and($row->company_id)->toBe($job->company_id);
    expect($row->source)->toBeNull()->and($row->cv_file_id)->toBeNull();
    expect($row->consent_given)->toBeTrue()->and($row->status)->toBe('new');
})->with(['0912345678', '+84912345678', '84 912 345 678']);

it('rejects invalid required fields and retains safe entered fields but never the captcha token', function (array $invalid, string $field) {
    $job = publishManagedJob(managedJob(jobManager()));
    $data = applicationFormData($this, $job, $invalid);
    $response = $this->from(route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]))
        ->post(route('applications.store', ['id' => $job->id]), $data)->assertSessionHasErrors($field);
    expect(session()->getOldInput('cf-turnstile-response'))->toBeNull();
    expect(session()->getOldInput('submission_token'))->toBeNull();
    if ($field !== 'full_name') {
        expect(session()->getOldInput('full_name'))->toBe($data['full_name']);
    }
    expect(DB::table('applications')->count())->toBe(0);
})->with([
    [['full_name' => ''], 'full_name'], [['phone' => ''], 'phone'], [['phone' => '123'], 'phone'],
    [['consent' => null], 'consent'], [['email' => 'invalid'], 'email'],
    [['portfolio_url' => 'javascript:alert(1)'], 'portfolio_url'], [['portfolio_url' => 'data:text/plain,x'], 'portfolio_url'],
]);

it('prevents double clicks and a fresh form with the same normalized phone on the same job', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $url = route('applications.store', ['id' => $job->id]);
    $data = applicationFormData($this, $job);
    $this->post($url, $data)->assertSessionHasNoErrors();
    $this->post($url, $data)->assertSessionHasErrors('submission_token');
    $this->post($url, applicationFormData($this, $job, ['phone' => '+84912345678']))->assertSessionHasErrors('phone');
    expect(DB::table('applications')->count())->toBe(1);
});

it('stores a checked private CV and link with source from the session without fetching the link', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $this->get(route('jobs.show', ['slug' => $job->slug, 'id' => $job->id, 'nguon' => 'zalo-thu']));
    $data = applicationFormData($this, $job, ['cv' => docxUpload(), 'portfolio_url' => 'https://example.org/portfolio']);
    $this->post(route('applications.store', ['id' => $job->id]), $data)->assertSessionHasNoErrors();
    $row = DB::table('applications')->first();
    expect($row->source)->toBe('zalo-thu')->and($row->portfolio_url)->toBe('https://example.org/portfolio');
    $file = DB::table('files')->where('id', $row->cv_file_id)->first();
    expect($file->scan_status)->toBe('pending');
    Storage::disk('candidate-files')->assertExists($file->path);
    Http::assertSentCount(1);
});

it('rolls back application and file rows and removes a newly stored CV when database saving fails', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $data = applicationFormData($this, $job, ['cv' => docxUpload()]);
    DB::listen(function (QueryExecuted $query) {
        if (str_starts_with($query->sql, 'insert into "applications"')) {
            throw new RuntimeException('Synthetic database failure');
        }
    });
    $this->post(route('applications.store', ['id' => $job->id]), $data)->assertSessionHasErrors('application');
    expect(DB::table('applications')->count())->toBe(0)->and(DB::table('files')->count())->toBe(0);
    expect(Storage::disk('candidate-files')->allFiles())->toBe([]);
});

it('rejects submission if the job expires after form loading', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $data = applicationFormData($this, $job);
    $this->travel(8)->days();
    $this->post(route('applications.store', ['id' => $job->id]), $data)->assertForbidden();
    expect(DB::table('applications')->count())->toBe(0);
});

it('rechecks the locked job after captcha verification before saving', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $data = applicationFormData($this, $job);
    Http::fake(function () use ($job) {
        $job->transitionTo(JobStatus::Paused);

        return Http::response(['success' => true]);
    });
    $this->post(route('applications.store', ['id' => $job->id]), $data)->assertForbidden();
    expect(DB::table('applications')->count())->toBe(0);
});

it('rejects and removes the CV if the expiry boundary passes during file inspection', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $data = applicationFormData($this, $job, ['cv' => docxUpload()]);
    $storage = app(StoreCandidateFile::class);
    $mock = Mockery::mock($storage)->makePartial();
    $mock->shouldReceive('storeCv')->once()->andReturnUsing(function (...$arguments) use ($storage) {
        $file = $storage->storeCv(...$arguments);
        $this->travel(8)->days();

        return $file;
    });
    app()->instance(StoreCandidateFile::class, $mock);
    $this->post(route('applications.store', ['id' => $job->id]), $data)->assertForbidden();
    expect(DB::table('applications')->count())->toBe(0)->and(DB::table('files')->count())->toBe(0);
    expect(Storage::disk('candidate-files')->allFiles())->toBe([]);
});

it('binds a form token to its job and session and rejects missing forged or busy tokens', function (string $mode) {
    $job = publishManagedJob(managedJob(jobManager()));
    $data = applicationFormData($this, $job);
    $url = route('applications.store', ['id' => $job->id]);
    if ($mode === 'job') {
        $other = publishManagedJob(managedJob(jobManager()));
        $url = route('applications.store', ['id' => $other->id]);
    } elseif ($mode === 'session') {
        $this->withCookie(config('session.cookie'), str_repeat('a', 40));
    } elseif ($mode === 'busy') {
        Cache::lock('application-form:'.hash('sha256', session()->getId().'|'.$data['submission_token']).':lock', 30)->get();
    } else {
        $data['submission_token'] = $mode === 'missing' ? '' : str_repeat('0', 64);
    }
    $this->post($url, $data)->assertSessionHasErrors('submission_token');
    Http::assertNothingSent();
    expect(DB::table('applications')->count())->toBe(0);
})->with(['job', 'session', 'busy', 'missing', 'forged']);

it('only grants Turnstile CSP on an accepting detail page and gives its script the request nonce', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $url = route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]);
    $response = $this->get($url)->assertOk();
    $csp = $response->headers->get('Content-Security-Policy');
    preg_match('/script-src ([^;]+)/', $csp, $script);
    expect($script[1])->toContain('https://challenges.cloudflare.com', "'nonce-")->not->toContain('unsafe-eval', 'unsafe-inline');
    preg_match("/'nonce-([^']+)'/", $csp, $nonce);
    $response->assertSee('nonce="'.$nonce[1].'"', false);
    expect($csp)->toContain('frame-src https://challenges.cloudflare.com');
    $job->transitionTo(JobStatus::Paused);
    foreach ([$url, '/', '/login', '/chinh-sach-du-lieu'] as $path) {
        expect($this->get($path)->headers->get('Content-Security-Policy'))->not->toContain('challenges.cloudflare.com', 'unsafe-eval');
    }
});

it('keeps submission idempotency in PostgreSQL even if a cache lease expires and the payload changes', function () {
    $job = publishManagedJob(managedJob(jobManager()));
    $data = applicationFormData($this, $job);
    $url = route('applications.store', ['id' => $job->id]);
    $this->post($url, $data)->assertSessionHasNoErrors();
    // Model a stale worker that still holds an issued token after its cache lock expired.
    Cache::put('application-form:'.hash('sha256', session()->getId().'|'.$data['submission_token']), $job->id, now()->addHour());
    $data['phone'] = '0987654321';
    $data['cf-turnstile-response'] = 'new-synthetic-token';
    $this->post($url, $data)->assertSessionHasErrors('submission_token');
    expect(DB::table('applications')->count())->toBe(1);
    expect(DB::table('applications')->value('submission_hash'))->toBe(hash('sha256', $data['submission_token']));
});

it('accepts a ten MiB image PDF through the submission pipeline with fake captcha and preserves original bytes', function () {
    if (! filter_var(getenv('PDF_SANDBOX_TESTS'), FILTER_VALIDATE_BOOL)) {
        test()->markTestSkipped('Requires the T23 Docker inspector; mandatory in CI.');
    }
    config(['pdf-inspection.enabled' => true]);
    $job = publishManagedJob(managedJob(jobManager()));
    $bytes = pdfFixtureBytes(image: true);
    $data = applicationFormData($this, $job, ['cv' => UploadedFile::fake()->createWithContent('sample.pdf', $bytes)]);
    $start = microtime(true);
    $this->post(route('applications.store', ['id' => $job->id]), $data)->assertSessionHasNoErrors();
    $elapsed = (microtime(true) - $start) * 1000;
    $file = DB::table('files')->first();
    expect($file->size)->toBe(10485760)->and($file->sha256)->toBe(hash('sha256', $bytes));
    expect(hash('sha256', Storage::disk('candidate-files')->get($file->path)))->toBe($file->sha256);
    file_put_contents(base_path('.runtime/t24-submission-benchmark.json'), json_encode([
        'bytes' => 10485760, 'elapsed_ms' => $elapsed, 'captcha' => 'Http::fake; excludes browser upload and external network latency',
    ], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
});
