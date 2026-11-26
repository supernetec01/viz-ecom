<?php

use App\Enums\Role;
use App\Services\RecordAudit;
use App\Support\ApplicationRestriction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/ApplicationPolicyFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => false]);
});

it('denies unpaid detail access without returning candidate contacts or notes', function () {
    $fixture = applicationAccessFixture();
    $this->actingAs($fixture['employer'])->get('/app/ho-so/'.$fixture['applicationId'])->assertForbidden()
        ->assertDontSee('CANDIDATE_VISIBLE_NAME')->assertDontSee('0912345678')->assertDontSee('PRIVATE_CONTACT')
        ->assertDontSee('PRIVATE_CV')->assertDontSee('private-portfolio')->assertDontSee('PRIVATE_INTERNAL_NOTES');
});

it('returns identical forbidden detail responses for foreign missing and malformed identifiers', function () {
    $fixture = applicationAccessFixture();
    $this->actingAs(jobManager(Role::Employer));
    $responses = [];
    foreach ([$fixture['applicationId'], (string) Str::ulid(), 'invalid-ulid'] as $id) {
        $responses[] = $this->getJson('/app/ho-so/'.$id)->assertForbidden()->getContent();
    }
    expect(array_unique($responses))->toHaveCount(1);
});

it('denies direct edits of foreign or unpaid applications before validating input', function (bool $foreign) {
    $f = applicationAccessFixture();
    $actor = $foreign ? jobManager(Role::Employer) : $f['employer'];
    $this->actingAs($actor)->postJson('/app/ho-so/'.$f['applicationId'], ['status' => 'hired'])->assertForbidden();
    expect(DB::table('applications')->where('id', $f['applicationId'])->value('status'))->toBe('new');
    expect(DB::table('audit_logs')->count())->toBe(0);
})->with([false, true]);

it('denies unassigned staff and soft deleted applications or companies for every role', function (string $case, Role $role) {
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $actor = match ($role) {
        Role::Admin => $f['admin'], Role::Employer => $f['employer'], default => jobManager(Role::Staff)
    };
    if ($case !== 'unassigned' && $role === Role::Staff) {
        $f['job']->company->staff()->attach($actor);
    }
    if ($case === 'application') {
        DB::table('applications')->where('id', $f['applicationId'])->update(['deleted_at' => now()]);
    } elseif ($case === 'company') {
        $f['job']->company->delete();
    } elseif ($role !== Role::Staff) {
        expect(true)->toBeTrue();

        return;
    }
    $this->actingAs($actor)->getJson('/app/ho-so/'.$f['applicationId'])->assertForbidden();
})->with(['application', 'company', 'unassigned'])->with([Role::Admin, Role::Staff, Role::Employer]);

it('rejects an expired grant at save without any changes or audit', function () {
    $this->freezeTime();
    $f = applicationAccessFixture();
    $grant = applicationAccessGrant($f, ['ends_at' => now()->addMinute()]);
    $this->actingAs($f['employer'])->get('/app/ho-so/'.$f['applicationId'])->assertOk();
    $this->travel(1)->minutes();
    $this->postJson('/app/ho-so/'.$f['applicationId'], ['status' => 'hired', 'internal_notes' => 'new', 'edit_version' => 0])->assertForbidden();
    expect(DB::table('applications')->where('id', $f['applicationId'])->value('internal_notes'))->toBe('PRIVATE_INTERNAL_NOTES');
    expect(DB::table('audit_logs')->count())->toBe(1);
});

it('fails closed on detail reads and rolls back saves if audit writing fails', function (string $action) {
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $this->mock(RecordAudit::class)->shouldReceive('record')->andThrow(new RuntimeException('PRIVATE_LOG_ERROR'));
    $this->actingAs($f['employer']);
    $url = '/app/ho-so/'.$f['applicationId'];
    $response = $action === 'show' ? $this->getJson($url) : $this->postJson($url, ['status' => 'hired', 'internal_notes' => 'new', 'edit_version' => 0]);
    $response->assertStatus(503)->assertDontSee('0912345678')->assertDontSee('PRIVATE_CONTACT')->assertDontSee('PRIVATE_LOG_ERROR');
    expect(DB::table('applications')->where('id', $f['applicationId'])->value('status'))->toBe('new');
})->with(['show', 'save']);

it('allows each authorized role to read contacts without automatically changing status', function (Role $role) {
    $f = applicationAccessFixture();
    $actor = match ($role) {
        Role::Admin => $f['admin'], Role::Employer => $f['employer'], default => jobManager(Role::Staff)
    };
    if ($role === Role::Employer) {
        applicationAccessGrant($f);
    }
    if ($role === Role::Staff) {
        $f['job']->company->staff()->attach($actor);
    }
    $this->actingAs($actor)->get('/app/ho-so/'.$f['applicationId'])->assertOk()->assertSee('0912345678')->assertSee('PRIVATE_CONTACT')
        ->assertSee('noopener noreferrer')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Cache-Control', 'no-store, private');
    expect(DB::table('applications')->where('id', $f['applicationId'])->value('status'))->toBe('new');
    expect(DB::table('audit_logs')->where('action', 'application.viewed_detail')->count())->toBe(1);
})->with([Role::Admin, Role::Staff, Role::Employer]);

it('saves escaped notes with editor identity and exactly one audit per changed field', function (Role $role) {
    $f = applicationAccessFixture();
    $actor = match ($role) {
        Role::Admin => $f['admin'], Role::Employer => $f['employer'], default => jobManager(Role::Staff)
    };
    if ($role === Role::Employer) {
        applicationAccessGrant($f);
    }
    if ($role === Role::Staff) {
        $f['job']->company->staff()->attach($actor);
    }
    $this->actingAs($actor)->post('/app/ho-so/'.$f['applicationId'], ['status' => 'interview', 'internal_notes' => '<script>PRIVATE_NOTE</script>', 'edit_version' => 0])->assertRedirect();
    expect(DB::table('audit_logs')->count())->toBe(2);
    $logs = json_encode(DB::table('audit_logs')->get());
    expect($logs)->not->toContain('PRIVATE_NOTE', '0912345678', 'PRIVATE_CONTACT', 'CANDIDATE_VISIBLE_NAME');
    $this->get('/app/ho-so/'.$f['applicationId'])->assertOk()->assertSee('&lt;script&gt;PRIVATE_NOTE&lt;/script&gt;', false)
        ->assertDontSee('<script>PRIVATE_NOTE', false)->assertSee($actor->name);
    expect(DB::table('applications')->where('id', $f['applicationId'])->value('note_updated_by'))->toBe($actor->id);
    expect(DB::table('audit_logs')->count())->toBe(3);
})->with([Role::Admin, Role::Staff, Role::Employer]);

it('rejects concurrent stale saves without overwriting the first editor', function () {
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $this->actingAs($f['employer']);
    $url = '/app/ho-so/'.$f['applicationId'];
    $this->post($url, ['status' => 'hired', 'internal_notes' => 'First editor', 'edit_version' => 0])->assertRedirect();
    $this->postJson($url, ['status' => 'rejected', 'internal_notes' => 'Second editor', 'edit_version' => 0])->assertStatus(409);
    expect(DB::table('applications')->where('id', $f['applicationId'])->value('internal_notes'))->toBe('First editor');
    expect(DB::table('audit_logs')->count())->toBe(2);
});

it('rejects invalid statuses oversized notes and forged versions', function (array $input) {
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $this->actingAs($f['employer'])->postJson('/app/ho-so/'.$f['applicationId'], array_replace(['status' => 'viewed', 'internal_notes' => 'ok', 'edit_version' => 0], $input))->assertStatus(422);
    expect(DB::table('audit_logs')->count())->toBe(0);
})->with([[['status' => 'arbitrary']], [['internal_notes' => str_repeat('Đ', 2001)]], [['edit_version' => -1]]]);

it('has no mutation routes for audit logs and only RecordAudit writes them', function () {
    foreach (Route::getRoutes() as $route) {
        expect($route->uri())->not->toContain('audit');
    }
    $f = applicationAccessFixture();
    expect(fn () => app(RecordAudit::class)->record($f['admin'], 'application.note_updated', $f['applicationId'], $f['job']->company_id, request(), ['note' => 'private']))->toThrow(InvalidArgumentException::class);
});

it('does not trust a client forwarded IP or retain arbitrary user agent text', function () {
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $this->actingAs($f['employer'])->withHeaders(['X-Forwarded-For' => '8.8.8.8', 'User-Agent' => 'Chrome PRIVATE_CONTACT@example.test'])
        ->get('/app/ho-so/'.$f['applicationId'])->assertOk();
    $audit = DB::table('audit_logs')->first();
    expect($audit->ip)->toBe('127.0.0.1')->and($audit->user_agent)->toBe('Chrome');
});

it('keeps the T43 restriction integration disabled until real flags exist', function () {
    expect(app(ApplicationRestriction::class)->blocked(1, 1))->toBeFalse();
});

it('only links paid records while the unpaid list has a locked label and no purchase button', function (bool $paid) {
    $f = applicationAccessFixture();
    if ($paid) {
        applicationAccessGrant($f);
    }
    $response = $this->actingAs($f['employer'])->get('/app/ho-so')->assertOk();
    $url = route('applications.detail', ['id' => $f['applicationId']]);
    if ($paid) {
        $response->assertSee($url, false);
    } else {
        $response->assertDontSee($url, false)->assertSee(__('applications.detail_locked'));
    }
    $response->assertDontSee('Yêu cầu mở hồ sơ');
})->with([false, true]);

it('accepts exactly the five manual status values', function (string $status) {
    $f = applicationAccessFixture();
    applicationAccessGrant($f);
    $this->actingAs($f['employer'])->post('/app/ho-so/'.$f['applicationId'], ['status' => $status, 'edit_version' => 0, 'internal_notes' => 'PRIVATE_INTERNAL_NOTES'])->assertRedirect();
    expect(DB::table('applications')->where('id', $f['applicationId'])->value('status'))->toBe($status);
    expect(DB::table('audit_logs')->count())->toBe($status === 'new' ? 0 : 1);
})->with(['new', 'viewed', 'interview', 'hired', 'rejected']);
