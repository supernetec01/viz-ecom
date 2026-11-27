<?php

use App\Enums\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/ApplicationPolicyFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => false]);
});

it('denies an unpaid viewer without exposing candidate contacts paths or signed file links', function () {
    $f = applicationAccessFixture();
    $this->actingAs($f['employer'])->get('/app/ho-so/'.$f['applicationId'].'/xem/'.$f['fileId'])->assertForbidden()
        ->assertDontSee('PRIVATE_CONTACT')->assertDontSee('0912345678')->assertDontSee('PRIVATE_CV')
        ->assertDontSee('signature=')->assertDontSee('private-portfolio');
});

it('returns the same forbidden viewer response for foreign and nonexistent applications', function () {
    $f = applicationAccessFixture();
    $this->actingAs(jobManager(Role::Employer));
    $existing = $this->getJson('/app/ho-so/'.$f['applicationId'].'/xem/'.$f['fileId'])->assertForbidden();
    $missing = $this->getJson('/app/ho-so/'.Str::ulid().'/xem/'.$f['fileId'])->assertForbidden();
    expect($existing->getContent())->toBe($missing->getContent());
});

function viewerFixture(string $scan = 'clean', string $preview = 'ready'): array
{
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $path = 'cv/'.$f['job']->company_id.'/'.$f['job']->id.'/'.$f['applicationId'].'/'.Str::uuid().'.docx';
    DB::table('files')->where('id', $f['fileId'])->update(['path' => $path, 'preview_path' => 'previews/'.$f['fileId'].'/'.hash('sha256', 'bytes').'.pdf',
        'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'scan_status' => $scan, 'preview_status' => $preview]);

    return $f;
}

it('denies foreign file IDs and revoked rights before showing a viewer or issuing a download', function (string $mode) {
    $f = viewerFixture();
    $other = viewerFixture();
    $this->actingAs($f['employer'])->get('/app/ho-so/'.$f['applicationId'].'/'.$mode.'/'.$other['fileId'])->assertForbidden();
    DB::table('access_grants')->where('job_id', $f['job']->id)->update(['status' => 'revoked']);
    $this->get('/app/ho-so/'.$f['applicationId'].'/'.$mode.'/'.$f['fileId'])->assertForbidden()->assertDontSee('signature=');
})->with(['xem', 'tai']);

it('requires login for the viewer', function () {
    $f = viewerFixture();
    $this->get('/app/ho-so/'.$f['applicationId'].'/xem/'.$f['fileId'])->assertRedirect('/app/login');
});

it('shows readiness without links to nonclean bytes and allows only the clean original after preview failure', function (string $scan, string $preview) {
    $f = viewerFixture($scan, $preview);
    $url = '/app/ho-so/'.$f['applicationId'].'/xem/'.$f['fileId'];
    $response = $this->actingAs($f['employer'])->get($url)->assertOk()->assertDontSee('signature=')->assertDontSee('PRIVATE_CV_PATH');
    $download = route('applications.file.download', ['id' => $f['applicationId'], 'file' => $f['fileId']]);
    if ($scan === 'clean') {
        $response->assertSee($download, false)->assertSee(__('files.'.($preview === 'failed' ? 'no_preview' : 'preview_pending')));
    } else {
        $response->assertDontSee($download, false)->assertSee(__('files.scan_'.$scan));
        $this->get($download)->assertForbidden();
    }
})->with([['pending', 'pending'], ['error', 'failed'], ['infected', 'failed'], ['clean', 'pending'], ['clean', 'failed']]);

it('shows a canvas viewer for each authorized role with strict nonce CSP no store and no index', function (Role $role) {
    $f = viewerFixture();
    $actor = match ($role) {
        Role::Admin => $f['admin'], Role::Employer => $f['employer'], default => jobManager(Role::Staff)
    };
    if ($role === Role::Staff) {
        $f['job']->company->staff()->attach($actor);
    }
    $response = $this->actingAs($actor)->get('/app/ho-so/'.$f['applicationId'].'/xem/'.$f['fileId'])->assertOk()
        ->assertSee('<canvas', false)->assertSee('signature=')->assertDontSee('PRIVATE_CONTACT')->assertDontSee('PRIVATE_INTERNAL_NOTES')
        ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
    $csp = $response->headers->get('Content-Security-Policy');
    expect($csp)->toContain("worker-src 'self'", "object-src 'none'")->not->toContain('unsafe-eval');
    expect(explode(';', explode('script-src ', $csp)[1])[0])->not->toContain('unsafe-inline');
    preg_match('/nonce="([^"]+)"/', $response->getContent(), $match);
    expect($csp)->toContain("'nonce-{$match[1]}'");
    // Rendering the shell does not claim file bytes were already viewed.
    expect(DB::table('audit_logs')->count())->toBe(0);
})->with([Role::Admin, Role::Staff, Role::Employer]);

it('generates a fresh signed download redirect for the original and keeps the detail page paths private', function () {
    $this->freezeTime();
    $f = viewerFixture();
    $this->actingAs($f['employer']);
    $response = $this->get('/app/ho-so/'.$f['applicationId'].'/tai/'.$f['fileId'])->assertRedirect();
    parse_str(parse_url($response->headers->get('Location'), PHP_URL_QUERY), $query);
    expect($query['expires'])->toBe((string) now()->addMinutes(5)->timestamp)->and($query['actor'])->toBe((string) $f['employer']->id);
    $this->get('/app/ho-so/'.$f['applicationId'])->assertOk()->assertSee(__('viewer.download'))->assertSee(__('viewer.open'))
        ->assertDontSee('previews/')->assertDontSee('PRIVATE_CV_NAME');
});
