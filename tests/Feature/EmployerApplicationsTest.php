<?php

use App\Enums\Role;
use App\Filament\Resources\ApplicationResource\Pages\ListApplications;
use App\Policies\ApplicationPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;

require_once __DIR__.'/../Support/ApplicationPolicyFixtures.php';

uses(RefreshDatabase::class);

it('returns 403 when staff or admin opens the employer only summary page', function (Role $role) {
    $this->actingAs(jobManager($role))->get('/app/ho-so')->assertForbidden();
})->with([Role::Staff, Role::Admin]);

it('denies the employer summary query for staff admins and stale locked actors', function (string $case) {
    $actor = jobManager(match ($case) {
        'staff' => Role::Staff,'admin' => Role::Admin,default => Role::Employer
    });
    if ($case === 'locked') {
        DB::table('users')->where('id', $actor->id)->update(['status' => 'locked']);
    }
    expect(fn () => app(ApplicationPolicy::class)->employerSummaryQuery($actor))->toThrow(HttpException::class);
})->with(['staff', 'admin', 'locked']);

it('requires login for the summary page', function () {
    $this->get('/app/ho-so')->assertRedirect();
});

function employerSummaryFixture(string $name = 'Nguyễn Văn Thử', string $title = 'Vị trí thử nghiệm'): array
{
    $fixture = applicationAccessFixture();
    DB::table('applications')->where('id', $fixture['applicationId'])->update(['full_name' => $name]);
    DB::table('jobs')->where('id', $fixture['job']->id)->update(['title' => $title]);

    return $fixture;
}

it('only returns summary columns for the linked company in SQL HTML and Livewire JSON whether paid or unpaid', function (bool $paid) {
    $fixture = employerSummaryFixture();
    $foreign = employerSummaryFixture('Foreign Candidate', 'FOREIGN_ONLY_JOB');
    if ($paid) {
        applicationAccessGrant($fixture);
    }
    $this->actingAs($fixture['employer'])->get('/app/ho-so')->assertOk()
        ->assertDontSee('PRIVATE_CONTACT')->assertDontSee('0912345678')->assertDontSee('PRIVATE_CV')->assertDontSee('PRIVATE_PREVIEW')
        ->assertDontSee('private-portfolio')->assertDontSee('PRIVATE_INTERNAL_NOTES')->assertDontSee('Foreign Candidate')->assertDontSee('FOREIGN_ONLY_JOB');
    $component = Livewire::test(ListApplications::class)->assertCanSeeTableRecords([$fixture['applicationId']])
        ->assertCanNotSeeTableRecords([$foreign['applicationId']])->assertSee('Nguyễn Văn Thử')->assertSee('Vị trí thử nghiệm');
    $rows = $component->instance()->getTableRecords();
    foreach ($rows as $row) {
        expect(array_keys($row->getAttributes()))->toEqualCanonicalizing(['id', 'full_name', 'job_id', 'job_title', 'submitted_at']);
    }
    $component->call('$refresh');
    $json = json_encode(['snapshot' => $component->snapshot, 'effects' => $component->effects], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    expect($json)->not->toContain('PRIVATE_CONTACT', '0912345678', 'PRIVATE_CV', 'PRIVATE_PREVIEW', 'private-portfolio', 'PRIVATE_INTERNAL_NOTES', 'Foreign Candidate', 'FOREIGN_ONLY_JOB',
        'phone_normalized', 'cv_file_id', 'portfolio_file_id', 'portfolio_url', 'internal_notes');
    $query = app(ApplicationPolicy::class)->employerSummaryQuery($fixture['employer']);
    expect($query->toSql())->not->toContain('applications.*', 'phone_normalized', 'cv_file_id', 'email', 'portfolio_url', 'internal_notes');
})->with([false, true]);

it('partially masks contact like name segments in SQL and prevents searching their original values', function (string $input, string $needle) {
    $fixture = employerSummaryFixture('Nguyễn '.$input.' Thử');
    $policy = app(ApplicationPolicy::class);
    $row = $policy->employerSummaryQuery($fixture['employer'])->firstOrFail();
    expect($row->full_name)->toContain('Nguyễn', __('applications.contact_hidden'), 'Thử')->not->toContain($needle);
    expect(DB::table('applications')->where('id', $fixture['applicationId'])->value('full_name'))->toBe('Nguyễn '.$input.' Thử');
    $this->actingAs($fixture['employer']);
    $component = Livewire::test(ListApplications::class)->assertCanSeeTableRecords([$fixture['applicationId']])->assertDontSee($needle);
    $payload = json_encode(['snapshot' => $component->snapshot, 'effects' => $component->effects], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    expect($payload)->not->toContain($input, $needle);
    $component->searchTable($needle)->assertCanNotSeeTableRecords([$fixture['applicationId']]);
})->with([
    ['0912345678', '0912345678'], ['+84 (0) 912 345 678', '912'], ['09.12-345-678', '345'],
    ['USER@EXAMPLE.COM', 'USER@EXAMPLE.COM'], ['user [at] example [dot] com', 'example'],
    ['user at example dot com', 'example'], ['example [dot] com', 'example'], ['0912abc345678', '345678'],
    ['https://example.com/cv', 'example.com'], ['www.example.com/cv', 'example.com'], ['zalo.me/example', 'zalo.me'],
    ['ｅｘａｍｐｌｅ．ｃｏｍ', 'example.com'], ["0912\u{200B}345678", '345678'],
    ['không chín một hai ba bốn năm sáu bảy tám', 'chín một'],
]);

it('preserves Unicode names punctuation and escapes markup without making it HTML', function () {
    $fixture = employerSummaryFixture('Đỗ O’Neil - 王 小明 <img src=x onerror=alert(1)>');
    $this->actingAs($fixture['employer']);
    Livewire::test(ListApplications::class)->assertSee('Đỗ O’Neil - 王 小明')
        ->assertSee('&lt;img', false)->assertDontSee('<img src=x onerror=', false);
});

it('searches names and job titles without letting an OR condition escape the company scope', function () {
    $fixture = employerSummaryFixture('Nguyễn Thử', 'Kho vận');
    $foreign = employerSummaryFixture('Khác', 'Foreign Marketing');
    $this->actingAs($fixture['employer']);
    Livewire::test(ListApplications::class)->searchTable('Nguyễn')->assertCanSeeTableRecords([$fixture['applicationId']]);
    Livewire::test(ListApplications::class)->searchTable('Kho vận')->assertCanSeeTableRecords([$fixture['applicationId']]);
    Livewire::test(ListApplications::class)->searchTable('Marketing')->assertCanNotSeeTableRecords([$fixture['applicationId'], $foreign['applicationId']]);
});

it('filters jobs within scope and does not reveal foreign jobs in select options', function () {
    $fixture = employerSummaryFixture();
    $foreign = employerSummaryFixture('Foreign Candidate', 'Foreign Job');
    $jobs = app(ApplicationPolicy::class)->employerSummaryJobs($fixture['employer']);
    expect($jobs)->toHaveKey($fixture['job']->id)->not->toHaveKey($foreign['job']->id);
    $this->actingAs($fixture['employer']);
    Livewire::test(ListApplications::class)->filterTable('job_id', $fixture['job']->id)->assertCanSeeTableRecords([$fixture['applicationId']]);
    Livewire::test(ListApplications::class)->filterTable('job_id', $foreign['job']->id)->assertCountTableRecords(0)->assertDontSee('Foreign Job');
});

it('converts whole date filters from Vietnam time to UTC with an exclusive next day boundary', function () {
    $fixture = employerSummaryFixture();
    DB::table('applications')->where('id', $fixture['applicationId'])->update(['submitted_at' => '2026-11-25 17:00:00+00']);
    $this->actingAs($fixture['employer']);
    Livewire::test(ListApplications::class)->filterTable('submitted', ['from' => '2026-11-26', 'until' => '2026-11-26'])
        ->assertCanSeeTableRecords([$fixture['applicationId']])->assertSee('26/11/2026 00:00');
    Livewire::test(ListApplications::class)->filterTable('submitted', ['from' => '2026-11-25', 'until' => '2026-11-25'])->assertCountTableRecords(0);
});

it('rejects malformed long or reversed filters before executing the query', function (string $kind) {
    $fixture = employerSummaryFixture();
    $policy = app(ApplicationPolicy::class);
    $query = $policy->employerSummaryQuery($fixture['employer']);
    match ($kind) {
        'search' => $policy->searchSummaries($query, str_repeat('x', 201)),
        'nul' => $policy->searchSummaries($query, "bad\0text"),
        'job' => $policy->filterSummaryJob($query, 'not-a-number'),
        'date' => $policy->filterSummaryDates($query, ['from' => '2026-02-31']),
        default => $policy->filterSummaryDates($query, ['from' => '2026-11-26', 'until' => '2026-11-25']),
    };
})->with(['search', 'nul', 'job', 'date', 'reversed'])->throws(ValidationException::class);

it('removes soft deleted applications and companies but retains jobs soft deleted or no longer verified', function () {
    $fixture = employerSummaryFixture();
    $policy = app(ApplicationPolicy::class);
    $fixture['job']->delete();
    $fixture['job']->company->forceFill(['verified_at' => null, 'verified_by' => null])->save();
    expect($policy->employerSummaryQuery($fixture['employer'])->count())->toBe(1);
    DB::table('applications')->where('id', $fixture['applicationId'])->update(['deleted_at' => now()]);
    expect($policy->employerSummaryQuery($fixture['employer'])->count())->toBe(0);
    DB::table('applications')->where('id', $fixture['applicationId'])->update(['deleted_at' => null]);
    $fixture['job']->company->delete();
    expect($policy->employerSummaryQuery($fixture['employer'])->count())->toBe(0);
});

it('rechecks membership and role when a mounted component sends the next Livewire request', function (string $change) {
    $fixture = employerSummaryFixture();
    $this->actingAs($fixture['employer']);
    $component = Livewire::test(ListApplications::class)->assertCanSeeTableRecords([$fixture['applicationId']]);
    if ($change === 'membership') {
        $fixture['job']->company->employers()->detach($fixture['employer']);
        $component->call('$refresh')->assertCanNotSeeTableRecords([$fixture['applicationId']])->assertDontSee('Nguyễn Văn Thử');
    } else {
        DB::table('users')->where('id', $fixture['employer']->id)->update(['role' => 'staff']);
        $component->call('$refresh')->assertForbidden();
    }
})->with(['membership', 'role']);

it('paginates deterministically and has no detail or file actions', function () {
    $fixture = employerSummaryFixture();
    $row = (array) DB::table('applications')->where('id', $fixture['applicationId'])->first();
    for ($i = 1; $i <= 12; $i++) {
        DB::table('applications')->insert(array_replace($row, ['id' => (string) Str::ulid(), 'phone_normalized' => '091234'.str_pad((string) $i, 4, '0', STR_PAD_LEFT)]));
    }
    $this->actingAs($fixture['employer']);
    $component = Livewire::test(ListApplications::class);
    $first = $component->instance()->getTableRecords()->pluck('id')->all();
    expect($first)->toHaveCount(10);
    $component->call('setPage', 2);
    $second = $component->instance()->getTableRecords()->pluck('id')->all();
    expect($second)->toHaveCount(3)->and(array_intersect($first, $second))->toBe([]);
    expect($component->instance()->getTable()->getRecordActions())->toBe([]);
});

it('includes every currently linked company and excludes an account with no company membership', function () {
    $fixture = employerSummaryFixture();
    $second = employerSummaryFixture('Trần Văn Mẫu', 'Vị trí thứ hai');
    $second['job']->company->employers()->attach($fixture['employer']);
    $this->actingAs($fixture['employer']);
    Livewire::test(ListApplications::class)->assertCanSeeTableRecords([$fixture['applicationId'], $second['applicationId']]);
    $fixture['job']->company->employers()->detach($fixture['employer']);
    $second['job']->company->employers()->detach($fixture['employer']);
    Livewire::test(ListApplications::class)->assertCountTableRecords(0);
});
