<?php

use App\Enums\JobStatus;
use App\Enums\Role;
use App\Filament\Resources\JobResource\Pages\CreateJob;
use App\Filament\Resources\JobResource\Pages\EditJob;
use App\Filament\Resources\JobResource\Pages\ListJobs;
use App\Models\Company;
use App\Models\Job;
use App\Models\User;
use App\Services\ManageJobs;
use App\Support\JobData;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('app')));

it('returns 403 for employer management pages', function () {
    $this->actingAs(User::factory()->create());
    $this->get('/app/tin-tuyen-dung')->assertForbidden();
    $this->get('/app/tin-tuyen-dung/create')->assertForbidden();
});

it('scopes list preview and edit URLs to assigned company jobs', function () {
    $staff = jobManager();
    $own = managedJob($staff);
    $other = managedJob(jobManager());
    $this->actingAs($staff);
    Livewire::test(ListJobs::class)->assertCanSeeTableRecords([$own])->assertCanNotSeeTableRecords([$other]);
    $this->get('/app/tin-tuyen-dung/'.$own->id)->assertOk();
    $this->get('/app/tin-tuyen-dung/'.$other->id)->assertNotFound();
    $this->get('/app/tin-tuyen-dung/'.$other->id.'/edit')->assertNotFound();
});

it('creates and edits a draft through Filament without accepting privileged fields', function (Role $role) {
    $actor = jobManager($role);
    $source = managedJob($actor);
    $this->actingAs($actor);
    $input = array_intersect_key($source->getAttributes(), JobData::rules());
    $input['title'] = 'Tin mới';
    Livewire::test(CreateJob::class)->fillForm($input)
        ->set('data.status', 'published')->set('data.created_by', 999999)->set('data.published_at', now()->toDateTimeString())
        ->call('create')->assertHasNoFormErrors();
    $job = Job::where('title', 'Tin mới')->sole();
    expect($job->status)->toBe(JobStatus::Draft)->and($job->created_by)->toBe($actor->id)->and($job->published_at)->toBeNull();
    Livewire::test(EditJob::class, ['record' => $job->id])->fillForm(['title' => 'Đã sửa'])->call('save')->assertHasNoFormErrors();
    expect($job->fresh()->title)->toBe('Đã sửa');
})->with([Role::Admin, Role::Staff]);

it('rejects unverified company and inverted salary range at the form', function () {
    $staff = jobManager();
    $source = managedJob($staff);
    $this->actingAs($staff);
    $input = array_intersect_key($source->getAttributes(), JobData::rules());
    Livewire::test(CreateJob::class)->fillForm($input + ['salary_min' => 200, 'salary_max' => 100])->call('create')->assertHasFormErrors(['salary_max']);
    $company = Company::create(['name' => 'Unverified']);
    $company->staff()->attach($staff);
    $input['company_id'] = $company->id;
    Livewire::test(CreateJob::class)->fillForm($input)->call('create')->assertHasFormErrors(['company_id']);
    expect(Job::count())->toBe(1);
});

it('denies service creation under another company and prevents reparenting existing jobs', function () {
    $staff = jobManager();
    $own = managedJob($staff);
    $other = managedJob(jobManager());
    $input = array_intersect_key($other->getAttributes(), JobData::rules());
    expect(fn () => app(ManageJobs::class)->create($staff, $input))->toThrow(AuthorizationException::class)
        ->and(fn () => app(ManageJobs::class)->update($staff, $own, $input))->toThrow(ValidationException::class);
    expect($own->fresh()->company_id)->not->toBe($other->company_id);
});

it('denies a live edit request after company assignment is revoked', function () {
    $staff = jobManager();
    $job = managedJob($staff);
    $this->actingAs($staff);
    $page = Livewire::test(EditJob::class, ['record' => $job->id])->fillForm(['title' => 'Unauthorized']);
    $job->company->staff()->detach($staff);
    $page->call('save')->assertNotFound();
    expect($job->fresh()->title)->not->toBe('Unauthorized');
});

it('strips scripts unsafe links event handlers and embeds while preserving formatting', function (string $field) {
    $staff = jobManager();
    $job = managedJob($staff);
    $this->actingAs($staff);
    $html = '<script>alert(1)</script><p onclick="bad()" style="background:url(javascript:bad())"><strong>Hợp lệ</strong></p><img src=x onerror="bad()"><svg onload="bad()"></svg><iframe src="https://evil.example"></iframe><a href="javascript:bad()">Xấu</a><a href="https://company.example">Tốt</a>';
    Livewire::test(EditJob::class, ['record' => $job->id])->fillForm([$field => $html])->call('save')->assertHasNoFormErrors();
    $stored = $job->fresh()->{$field};
    expect($stored)->toContain('<strong>Hợp lệ</strong>')->toContain('https://company.example')->toContain('noopener noreferrer')
        ->not->toContain('<script', 'onclick', 'style=', 'javascript:', '<img', 'onerror', '<svg', '<iframe', 'alert(1)');
    $this->get('/app/tin-tuyen-dung/'.$job->id)->assertOk()->assertSee('<strong>Hợp lệ</strong>', false)->assertDontSee('javascript:bad()', false);
})->with(['description', 'requirements', 'benefits']);

it('sanitizes legacy database HTML again on preview', function () {
    $staff = jobManager();
    $job = managedJob($staff);
    DB::table('jobs')->where('id', $job->id)->update(['description' => '<p>Legacy</p><script>legacyUnsafe()</script>']);
    $this->actingAs($staff)->get('/app/tin-tuyen-dung/'.$job->id)->assertOk()->assertSee('Legacy')->assertDontSee('legacyUnsafe()', false);
});

it('accepts Vietnamese rich text within the character limit even when UTF8 exceeds 50000 bytes', function () {
    $staff = jobManager();
    $job = managedJob($staff);
    $html = '<p>'.str_repeat('ề', 20000).'</p>';
    $job->description = $html;
    $job->save();
    expect($job->fresh()->description)->toBe($html);
});

it('duplicates only content into a fresh draft with no dates or source relations', function () {
    $staff = jobManager();
    $source = publishManagedJob(managedJob($staff));
    $this->actingAs($staff);
    Livewire::test(ListJobs::class)->callAction(TestAction::make('duplicate')->table($source))->assertHasNoActionErrors();
    $copy = Job::whereKeyNot($source->id)->sole();
    expect($copy->status)->toBe(JobStatus::Draft)->and($copy->published_at)->toBeNull()->and($copy->expires_at)->toBeNull()
        ->and($copy->slug)->not->toBe($source->slug)->and($copy->title)->toBe($source->title)
        ->and($copy->created_by)->toBe($staff->id)->and($copy->company_id)->toBe($source->company_id)
        ->and($source->fresh()->status)->toBe(JobStatus::Published);
});

it('pauses and resumes through Filament keeping original publication and expiry dates', function () {
    $staff = jobManager();
    $job = publishManagedJob(managedJob($staff));
    $published = $job->published_at->toISOString();
    $expiry = $job->expires_at->toISOString();
    $this->actingAs($staff);
    $table = Livewire::test(ListJobs::class);
    $table->callAction(TestAction::make('pause')->table($job))->assertHasNoActionErrors();
    expect($job->fresh()->status)->toBe(JobStatus::Paused);
    $this->get('/app/tin-tuyen-dung/'.$job->id.'/edit')->assertForbidden();
    $table->callAction(TestAction::make('resume')->table($job))->assertHasNoActionErrors();
    $current = $job->fresh();
    expect($current->status)->toBe(JobStatus::Published)->and($current->published_at->toISOString())->toBe($published)->and($current->expires_at->toISOString())->toBe($expiry);
});

it('rejects paused content and date edits both at service and model boundaries', function (string $field) {
    $staff = jobManager();
    $job = publishManagedJob(managedJob($staff));
    $job->transitionTo(JobStatus::Paused);
    expect(fn () => app(ManageJobs::class)->update($staff, $job, ['title' => 'Changed']))->toThrow(AuthorizationException::class);
    $job->{$field} = match ($field) {
        'published_at' => $job->published_at->addDay(),
        'expires_at' => $job->expires_at->addDay(),
        'salary_min' => 100,
        default => 'Changed',
    };
    expect(fn () => $job->save())->toThrow(ValidationException::class);
    expect($job->fresh()->title)->not->toBe('Changed');
})->with(['title', 'description', 'salary_min', 'published_at', 'expires_at']);

it('expires a paused job at or after expiry instead of resuming and never extends its expiry', function (int $seconds) {
    $staff = jobManager();
    $job = publishManagedJob(managedJob($staff));
    $job->transitionTo(JobStatus::Paused);
    $expiry = $job->expires_at->toISOString();
    $this->travelTo($job->expires_at->addSeconds($seconds));
    $this->actingAs($staff);
    Livewire::test(ListJobs::class)->callAction(TestAction::make('resume')->table($job))->assertHasNoActionErrors();
    expect($job->fresh()->status)->toBe(JobStatus::Expired)->and($job->fresh()->expires_at->toISOString())->toBe($expiry);
})->with([0, 1]);

it('redrafts paused jobs before editing and makes closing terminal', function () {
    $staff = jobManager();
    $job = publishManagedJob(managedJob($staff));
    $this->actingAs($staff);
    $table = Livewire::test(ListJobs::class);
    $table->callAction(TestAction::make('pause')->table($job))->assertHasNoActionErrors();
    $table->callAction(TestAction::make('redraft')->table($job))->assertHasNoActionErrors();
    $draft = $job->fresh();
    expect($draft->status)->toBe(JobStatus::Draft)->and($draft->expires_at)->toBeNull()->and($draft->published_at)->toBeNull();
    Livewire::test(EditJob::class, ['record' => $job->id])->fillForm(['title' => 'Revised'])->call('save')->assertHasNoFormErrors();
    $published = publishManagedJob($job->fresh());
    Livewire::test(ListJobs::class)->callAction(TestAction::make('close')->table($published))->assertHasNoActionErrors();
    expect($job->fresh()->status)->toBe(JobStatus::Closed);
    Livewire::test(ListJobs::class)->assertActionHidden(TestAction::make('redraft')->table($job));
});
