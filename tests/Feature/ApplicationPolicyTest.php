<?php

use App\Enums\JobStatus;
use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Application;
use App\Policies\ApplicationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

require_once __DIR__.'/../Support/ApplicationPolicyFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.debug' => false]);
    Route::get('/_test/application-policy/{action}/{id}/{file?}', function (string $action, string $id, ?int $file = null) {
        Gate::forUser(request()->user())->authorize($action, [Application::class, $id, ...($file === null ? [] : [$file])]);

        return response()->json(['allowed' => true]);
    });
});

function policyTestUrl(array $fixture, string $action, ?string $id = null): string
{
    return '/_test/application-policy/'.$action.'/'.($id ?? $fixture['applicationId'])
        .($action === 'download' ? '/'.$fixture['fileId'] : '');
}

it('returns 403 to an unpaid employer for details and download without exposing candidate data', function (string $action) {
    $fixture = applicationAccessFixture();
    $this->actingAs($fixture['employer'])->getJson(policyTestUrl($fixture, $action))->assertForbidden()
        ->assertDontSee('CANDIDATE_VISIBLE_NAME')->assertDontSee('0912345678')->assertDontSee('PRIVATE_CONTACT')
        ->assertDontSee('PRIVATE_CV')->assertDontSee('private-portfolio');
})->with(['viewDetails', 'download']);

it('denies every action across companies and to unassigned staff', function (string $action, string $role) {
    $fixture = applicationAccessFixture();
    applicationAccessGrant($fixture);
    $actor = jobManager(Role::from($role));
    $this->actingAs($actor)->getJson(policyTestUrl($fixture, $action))->assertForbidden();
})->with(['viewSummary', 'viewDetails', 'download'])->with(['employer', 'staff']);

it('denies details and download when the grant is expired revoked future or for another job', function (string $action, string $state) {
    $this->freezeTime();
    $fixture = applicationAccessFixture();
    if ($state === 'another_job') {
        $other = $fixture;
        $other['job'] = managedJob($fixture['admin']);
        $other['job']->company->employers()->attach($fixture['employer']);
        applicationAccessGrant($other);
    } else {
        applicationAccessGrant($fixture, match ($state) {
            'expired' => ['starts_at' => now()->subDay(), 'ends_at' => now()],
            'revoked' => ['status' => 'revoked'],
            default => ['starts_at' => now()->addHour(), 'ends_at' => now()->addDay()],
        });
    }
    $this->actingAs($fixture['employer'])->getJson(policyTestUrl($fixture, $action))->assertForbidden();
})->with(['viewDetails', 'download'])->with(['expired', 'revoked', 'future', 'another_job']);

it('denies locked accounts in the policy itself even with a previously loaded actor', function (string $action, string $role) {
    $fixture = applicationAccessFixture();
    applicationAccessGrant($fixture);
    $actor = match ($role) {
        'admin' => $fixture['admin'], 'staff' => jobManager(Role::Staff), default => $fixture['employer']
    };
    if ($role === 'staff') {
        $fixture['job']->company->staff()->attach($actor);
    }
    DB::table('users')->where('id', $actor->id)->update(['status' => UserStatus::Locked->value]);
    $this->actingAs($actor)->getJson(policyTestUrl($fixture, $action))->assertForbidden();
})->with(['viewSummary', 'viewDetails', 'download'])->with(['admin', 'staff', 'employer']);

it('returns the same forbidden response for nonexistent and other company application IDs', function () {
    $fixture = applicationAccessFixture();
    $this->actingAs(jobManager(Role::Employer));
    $existing = $this->getJson(policyTestUrl($fixture, 'viewDetails'))->assertForbidden();
    $missing = $this->getJson(policyTestUrl($fixture, 'viewDetails', (string) Str::ulid()))->assertForbidden();
    expect($existing->getContent())->toBe($missing->getContent());
});

it('allows an active linked employer to view summary without buying access', function () {
    $fixture = applicationAccessFixture();
    $this->actingAs($fixture['employer'])->getJson(policyTestUrl($fixture, 'viewSummary'))->assertOk();
});

it('allows the correct paid job including applications submitted before the grant started', function (string $action) {
    $fixture = applicationAccessFixture();
    applicationAccessGrant($fixture, ['starts_at' => now()]);
    $this->actingAs($fixture['employer'])->getJson(policyTestUrl($fixture, $action))->assertOk();
})->with(['viewSummary', 'viewDetails', 'download']);

it('allows admins and assigned staff without purchases but still denies unclean file downloads', function (string $action, string $role) {
    $fixture = applicationAccessFixture();
    $actor = $role === 'admin' ? $fixture['admin'] : jobManager(Role::Staff);
    if ($role === 'staff') {
        $fixture['job']->company->staff()->attach($actor);
    }
    $this->actingAs($actor)->getJson(policyTestUrl($fixture, $action))->assertOk();
    if ($action === 'download') {
        DB::table('files')->where('id', $fixture['fileId'])->update(['scan_status' => 'infected']);
        $this->getJson(policyTestUrl($fixture, $action))->assertForbidden();
    }
})->with(['viewSummary', 'viewDetails', 'download'])->with(['admin', 'staff']);

it('keeps access for paused expired closed or soft deleted jobs and companies whose verification was revoked', function (string $state) {
    $fixture = applicationAccessFixture();
    applicationAccessGrant($fixture);
    if ($state === 'deleted') {
        $fixture['job']->delete();
    } elseif ($state === 'unverified') {
        $fixture['job']->company->forceFill(['verified_at' => null, 'verified_by' => null])->save();
    } else {
        $fixture['job']->transitionTo(JobStatus::from($state));
    }
    $this->actingAs($fixture['employer'])->getJson(policyTestUrl($fixture, 'viewDetails'))->assertOk();
    $this->getJson(policyTestUrl($fixture, 'download'))->assertOk();
})->with(['paused', 'expired', 'closed', 'deleted', 'unverified']);

it('blocks soft deleted applications and companies for every role', function (string $resource, string $role) {
    $fixture = applicationAccessFixture();
    applicationAccessGrant($fixture);
    $actor = $role === 'admin' ? $fixture['admin'] : $fixture['employer'];
    if ($resource === 'application') {
        DB::table('applications')->where('id', $fixture['applicationId'])->update(['deleted_at' => now()]);
    } else {
        $fixture['job']->company->delete();
    }
    foreach (['viewSummary', 'viewDetails', 'download'] as $action) {
        $this->actingAs($actor)->getJson(policyTestUrl($fixture, $action))->assertForbidden();
    }
})->with(['application', 'company'])->with(['admin', 'employer']);

it('checks both sides of exact grant time boundaries using the same actor and no new login', function () {
    $this->freezeTime();
    $fixture = applicationAccessFixture();
    $start = now()->addHour()->setMicrosecond(123456);
    $end = $start->copy()->addHour();
    applicationAccessGrant($fixture, ['starts_at' => $start, 'ends_at' => $end]);
    $this->actingAs($fixture['employer']);
    foreach (['viewDetails', 'download'] as $action) {
        $this->getJson(policyTestUrl($fixture, $action))->assertForbidden();
    }
    $this->travelTo($start);
    foreach (['viewDetails', 'download'] as $action) {
        $this->getJson(policyTestUrl($fixture, $action))->assertOk();
    }
    $this->travelTo($end->copy()->subMicrosecond());
    $this->getJson(policyTestUrl($fixture, 'download'))->assertOk();
    $this->travelTo($end);
    foreach (['viewDetails', 'download'] as $action) {
        $this->getJson(policyTestUrl($fixture, $action))->assertForbidden();
    }
    $this->getJson(policyTestUrl($fixture, 'viewSummary'))->assertOk();
});

it('blocks access immediately after revocation removal of membership or role changes', function (string $change) {
    $fixture = applicationAccessFixture();
    $grant = applicationAccessGrant($fixture);
    $this->actingAs($fixture['employer']);
    $this->getJson(policyTestUrl($fixture, 'download'))->assertOk();
    match ($change) {
        'grant' => $grant->forceFill(['status' => 'revoked'])->save(),
        'membership' => $fixture['job']->company->employers()->detach($fixture['employer']),
        default => DB::table('users')->where('id', $fixture['employer']->id)->update(['role' => 'staff']),
    };
    $this->getJson(policyTestUrl($fixture, 'viewDetails'))->assertForbidden();
    $this->getJson(policyTestUrl($fixture, 'download'))->assertForbidden();
})->with(['grant', 'membership', 'role']);

it('rechecks staff assignment and admin role instead of trusting the authenticated object', function (string $role) {
    $fixture = applicationAccessFixture();
    $actor = $role === 'admin' ? $fixture['admin'] : jobManager(Role::Staff);
    if ($role === 'staff') {
        $fixture['job']->company->staff()->attach($actor);
    }
    $this->actingAs($actor)->getJson(policyTestUrl($fixture, 'viewDetails'))->assertOk();
    if ($role === 'staff') {
        $fixture['job']->company->staff()->detach($actor);
    } else {
        DB::table('users')->where('id', $actor->id)->update(['role' => 'employer']);
    }
    $this->getJson(policyTestUrl($fixture, 'viewDetails'))->assertForbidden();
})->with(['admin', 'staff']);

it('denies other jobs in the same company and files attached to another application', function () {
    $fixture = applicationAccessFixture();
    applicationAccessGrant($fixture);
    $job = managedJob($fixture['admin']);
    $job->company_id = $fixture['job']->company_id;
    $job->save();
    $id = (string) Str::ulid();
    $row = (array) DB::table('applications')->where('id', $fixture['applicationId'])->first();
    DB::table('applications')->insert(array_replace($row, ['id' => $id, 'job_id' => $job->id]));
    $this->actingAs($fixture['employer'])->getJson(policyTestUrl($fixture, 'viewDetails', $id))->assertForbidden();
    $this->getJson(policyTestUrl($fixture, 'download', $id))->assertForbidden();
    $otherFile = DB::table('files')->insertGetId(['path' => 'OTHER_FILE', 'original_name' => 'other.pdf', 'mime_type' => 'application/pdf',
        'size' => 5, 'sha256' => hash('sha256', 'other'), 'scan_status' => 'clean', 'preview_status' => 'ready']);
    DB::table('applications')->where('id', $id)->update(['cv_file_id' => $otherFile]);
    $this->getJson('/_test/application-policy/download/'.$fixture['applicationId'].'/'.$otherFile)->assertForbidden();
});

it('uses shared readiness for every role and scan state and allows a clean original with a failed preview', function (string $status, string $role) {
    $fixture = applicationAccessFixture();
    applicationAccessGrant($fixture);
    $actor = match ($role) {
        'admin' => $fixture['admin'], 'staff' => jobManager(Role::Staff), default => $fixture['employer']
    };
    if ($role === 'staff') {
        $fixture['job']->company->staff()->attach($actor);
    }
    DB::table('files')->where('id', $fixture['fileId'])->update(['scan_status' => $status, 'preview_status' => 'failed']);
    $response = $this->actingAs($actor)->getJson(policyTestUrl($fixture, 'download'));
    if ($status === 'clean') {
        $response->assertOk();
    } else {
        $response->assertForbidden();
    }
})->with(['pending', 'error', 'infected', 'clean'])->with(['admin', 'staff', 'employer']);

it('denies guests and malformed IDs without looking up private data', function () {
    $fixture = applicationAccessFixture();
    $this->getJson(policyTestUrl($fixture, 'viewDetails'))->assertForbidden();
    $this->actingAs($fixture['employer'])->getJson(policyTestUrl($fixture, 'viewDetails', 'not-a-ulid'))->assertForbidden();
});

it('does not select candidate contact or file columns while denying a request', function () {
    $fixture = applicationAccessFixture();
    $queries = [];
    DB::listen(function ($event) use (&$queries) {
        if (str_starts_with($event->sql, 'select') && str_contains($event->sql, '"applications"')) {
            $queries[] = $event->sql;
        }
    });
    $this->actingAs($fixture['employer'])->get(policyTestUrl($fixture, 'viewDetails'))->assertForbidden()
        ->assertDontSee('0912345678')->assertDontSee('PRIVATE_CONTACT')->assertDontSee('private-portfolio')->assertDontSee('PRIVATE_CV');
    expect($queries)->not->toBeEmpty();
    foreach ($queries as $sql) {
        expect($sql)->not->toContain('phone_normalized', 'email', 'cv_file_id', 'portfolio_file_id', 'portfolio_url', 'internal_notes');
    }
});

it('fails closed for a grant whose order no longer meets the paid invariant', function () {
    $fixture = applicationAccessFixture();
    $grant = applicationAccessGrant($fixture);
    DB::table('orders')->where('id', $grant->order_id)->update(['status' => 'cancelled']);
    $this->actingAs($fixture['employer'])->getJson(policyTestUrl($fixture, 'viewDetails'))->assertForbidden();
    $this->getJson(policyTestUrl($fixture, 'download'))->assertForbidden();
});

it('omits soft deleted application IDs from the existing admin file metadata page', function (string $resource) {
    $fixture = applicationAccessFixture();
    if ($resource === 'application') {
        DB::table('applications')->where('id', $fixture['applicationId'])->update(['deleted_at' => now()]);
    } else {
        $fixture['job']->company->delete();
    }
    expect(app(ApplicationPolicy::class)->fileApplicationIds($fixture['admin'], $fixture['fileId']))->toBe([]);
})->with(['application', 'company']);
