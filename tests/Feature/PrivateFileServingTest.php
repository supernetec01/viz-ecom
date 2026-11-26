<?php

use App\Enums\Role;
use App\Services\CandidateFileLease;
use App\Services\PrivateFileLinks;
use App\Services\RecordAudit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/ApplicationPolicyFixtures.php';
require_once __DIR__.'/../Support/CvFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => false]);
    Storage::fake('candidate-files');
});

function privateServingFixture(): array
{
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $bytes = file_get_contents(__DIR__.'/../Fixtures/pdf/generated/clean.pdf');
    $path = 'cv/'.$f['job']->company_id.'/'.$f['job']->id.'/'.$f['applicationId'].'/'.Str::uuid().'.pdf';
    Storage::disk('candidate-files')->put($path, $bytes);
    DB::table('files')->where('id', $f['fileId'])->update(['path' => $path, 'preview_path' => $path,
        'sha256' => hash('sha256', $bytes), 'scanned_sha256' => hash('sha256', $bytes), 'size' => strlen($bytes)]);

    return $f + compact('bytes', 'path');
}

function privateServingUrl(array $f, string $mode = 'download', array $overrides = []): string
{
    return URL::temporarySignedRoute('applications.file', now()->addMinutes(5), array_replace([
        'id' => $f['applicationId'], 'file' => $f['fileId'], 'mode' => $mode, 'actor' => $f['employer']->id,
    ], $overrides));
}

it('denies an unpaid employer even with a correctly signed private file URL', function () {
    $f = applicationAccessFixture();
    $url = URL::temporarySignedRoute('applications.file', now()->addMinutes(5), ['id' => $f['applicationId'], 'file' => $f['fileId'], 'mode' => 'download', 'actor' => $f['employer']->id]);
    $this->actingAs($f['employer'])->get($url)->assertForbidden()->assertDontSee('PRIVATE_CV');
});

it('denies requests without a signature before returning any private file data', function () {
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $this->actingAs($f['employer'])->get('/app/ho-so/'.$f['applicationId'].'/tep/'.$f['fileId'].'/download')->assertForbidden();
});

it('rejects expired signatures and tampered application file mode or actor parameters', function (string $case) {
    $this->freezeTime();
    $f = privateServingFixture();
    $url = privateServingUrl($f);
    $url = match ($case) {
        'application' => str_replace($f['applicationId'], (string) Str::ulid(), $url),
        'file' => str_replace('/tep/'.$f['fileId'].'/', '/tep/999999/', $url),
        'mode' => str_replace('/download', '/inline', $url),
        'actor' => str_replace('actor='.$f['employer']->id, 'actor=999999', $url), default => $url,
    };
    if ($case === 'expired') {
        $this->travel(6)->minutes();
    }
    $response = $this->actingAs($f['employer'])->get($url)->assertForbidden()->assertDontSee('%PDF');
    expect($response->headers->get('Content-Security-Policy'))->toContain("script-src 'none'", 'sandbox')->not->toContain('unsafe-eval');
    expect(DB::table('audit_logs')->count())->toBe(0);
})->with(['expired', 'application', 'file', 'mode', 'actor']);

it('does not serve nonclean files to any role even with a valid signature', function (Role $role, string $scan) {
    $f = privateServingFixture();
    $actor = match ($role) {
        Role::Admin => $f['admin'], Role::Employer => $f['employer'], default => jobManager(Role::Staff)
    };
    if ($role === Role::Staff) {
        $f['job']->company->staff()->attach($actor);
    }
    DB::table('files')->where('id', $f['fileId'])->update(['scan_status' => $scan]);
    $this->actingAs($actor)->get(privateServingUrl($f, overrides: ['actor' => $actor->id]))->assertForbidden();
})->with([Role::Admin, Role::Staff, Role::Employer])->with(['pending', 'infected', 'error']);

it('rechecks revocation expiry membership and account state on reuse of an old signed URL', function (string $case) {
    $this->freezeTime();
    $f = privateServingFixture();
    $url = privateServingUrl($f);
    $this->actingAs($f['employer'])->get($url)->assertOk();
    match ($case) {
        'revoked' => DB::table('access_grants')->where('job_id', $f['job']->id)->update(['status' => 'revoked']),
        'expired' => DB::table('access_grants')->where('job_id', $f['job']->id)->update(['ends_at' => now()]),
        'membership' => $f['job']->company->employers()->detach($f['employer']),
        default => DB::table('users')->where('id', $f['employer']->id)->update(['status' => 'locked']),
    };
    $response = $this->get($url);
    $case === 'locked' ? $response->assertRedirect() : $response->assertForbidden();
    $response->assertDontSee('%PDF');
    expect(DB::table('audit_logs')->count())->toBe(1);
})->with(['revoked', 'expired', 'membership', 'locked']);

it('blocks a shared signed link even for another paid user of the same company', function () {
    $f = privateServingFixture();
    $other = jobManager(Role::Employer);
    $f['job']->company->employers()->attach($other);
    $this->actingAs($other)->get(privateServingUrl($f))->assertForbidden();
});

it('denies a foreign or unattached file despite valid signatures', function (bool $foreign) {
    $f = privateServingFixture();
    $other = privateServingFixture();
    $this->actingAs($f['employer'])->get(privateServingUrl($f, overrides: $foreign
        ? ['id' => $other['applicationId'], 'file' => $other['fileId']]
        : ['file' => $other['fileId']]))->assertForbidden();
})->with([false, true]);

it('fails closed for a missing changed or unsafe original path', function (string $case) {
    $f = privateServingFixture();
    match ($case) {
        'missing' => Storage::disk('candidate-files')->delete($f['path']),
        'changed' => Storage::disk('candidate-files')->put($f['path'], 'CHANGED_PRIVATE_BYTES'),
        default => DB::table('files')->where('id', $f['fileId'])->update(['path' => '../outside.pdf']),
    };
    $response = $this->actingAs($f['employer'])->get(privateServingUrl($f));
    expect($response->status())->toBeIn([403, 503]);
    $response->assertDontSee('CHANGED_PRIVATE_BYTES');
    expect(DB::table('audit_logs')->count())->toBe(0);
})->with(['missing', 'changed', 'unsafe']);

it('does not stream bytes if the audit writer fails', function () {
    $f = privateServingFixture();
    $this->mock(RecordAudit::class)->shouldReceive('record')->andThrow(new RuntimeException('PRIVATE_FAILURE'));
    $this->actingAs($f['employer'])->get(privateServingUrl($f))->assertStatus(503)->assertDontSee('%PDF')->assertDontSee('PRIVATE_FAILURE');
});

it('streams the original or preview with private CSP and exactly one audit', function (string $mode) {
    $f = privateServingFixture();
    $url = app(PrivateFileLinks::class)->temporary($f['employer'], $f['applicationId'], $f['fileId'], $mode);
    $response = $this->actingAs($f['employer'])->get($url)->assertOk()->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    expect($response->streamedContent())->toBe($f['bytes']);
    expect($response->headers->get('Content-Disposition'))->toStartWith($mode === 'inline' ? 'inline;' : 'attachment;')->not->toContain('PRIVATE_CV_NAME');
    expect($response->headers->get('Content-Security-Policy'))->toContain("script-src 'none'", 'sandbox')->not->toContain('unsafe-eval', 'unsafe-inline');
    expect(DB::table('audit_logs')->count())->toBe(1);
    expect(DB::table('audit_logs')->value('action'))->toBe($mode === 'inline' ? 'application.file_viewed' : 'application.file_downloaded');
    expect(DB::table('audit_logs')->value('metadata'))->toContain('file_id')->not->toContain('PRIVATE');
})->with(['download', 'inline']);

it('allows a clean original with failed preview but denies inline', function () {
    $f = privateServingFixture();
    DB::table('files')->where('id', $f['fileId'])->update(['preview_status' => 'failed', 'preview_path' => null]);
    $this->actingAs($f['employer'])->get(privateServingUrl($f, 'inline'))->assertForbidden();
    $this->get(privateServingUrl($f))->assertOk();
});

it('serves the DOCX original for download and its distinct checked PDF for inline', function () {
    $f = privateServingFixture();
    $upload = docxUpload();
    $docx = file_get_contents($upload->getPathname());
    $path = substr($f['path'], 0, -3).'docx';
    Storage::disk('candidate-files')->put($path, $docx);
    $hash = hash('sha256', $docx);
    $preview = 'previews/'.$f['fileId'].'/'.$hash.'.pdf';
    Storage::disk('candidate-files')->put($preview, $f['bytes']);
    DB::table('files')->where('id', $f['fileId'])->update(['path' => $path, 'preview_path' => $preview,
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'sha256' => $hash, 'scanned_sha256' => $hash, 'size' => strlen($docx)]);
    $response = $this->actingAs($f['employer'])->get(privateServingUrl($f))->assertOk()
        ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect($response->streamedContent())->toBe($docx);
    $previewResponse = $this->get(privateServingUrl($f, 'inline'))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    expect($previewResponse->streamedContent())->toBe($f['bytes']);
});

it('never follows a preview path outside its deterministic checked location', function () {
    $f = privateServingFixture();
    DB::table('files')->where('id', $f['fileId'])->update(['preview_path' => '../outside.pdf']);
    $this->actingAs($f['employer'])->get(privateServingUrl($f, 'inline'))->assertForbidden();
});

it('cannot issue private links without current policy and readiness', function () {
    $f = privateServingFixture();
    DB::table('access_grants')->where('job_id', $f['job']->id)->update(['status' => 'revoked']);
    expect(fn () => app(PrivateFileLinks::class)->temporary($f['employer'], $f['applicationId'], $f['fileId'], 'download'))
        ->toThrow(HttpException::class);
});

it('does not serve when the processor holds the original lease', function () {
    $f = privateServingFixture();
    $lease = CandidateFileLease::acquire('candidate:'.$f['path']);
    try {
        $this->actingAs($f['employer'])->get(privateServingUrl($f))->assertStatus(503);
    } finally {
        $lease->release();
    }
});

it('requires authentication even when the private file signature is valid', function () {
    $f = privateServingFixture();
    $this->get(privateServingUrl($f))->assertRedirect('/app/login');
});
