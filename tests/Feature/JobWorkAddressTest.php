<?php

use App\Enums\JobStatus;
use App\Filament\Resources\JobResource\Pages\CreateJob;
use App\Filament\Resources\JobResource\Pages\EditJob;
use App\Filament\Resources\JobResource\Pages\ListJobs;
use App\Models\Job;
use App\Models\Location;
use App\Services\ManageJobs;
use App\Services\SubmitJob;
use App\Support\JobData;
use Filament\Actions\Testing\TestAction;
use Filament\Facades\Filament;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

uses(RefreshDatabase::class);
beforeEach(fn () => Filament::setCurrentPanel(Filament::getPanel('app')));

it('requires ward and detailed workplace address for new jobs', function () {
    $job = managedJob(jobManager());
    $data = array_intersect_key($job->getAttributes(), JobData::rules());
    unset($data['ward_name'], $data['work_address']);
    expect(fn () => JobData::validate($data))->toThrow(ValidationException::class);
});

it('rejects incomplete oversized or HTML workplace addresses at the model boundary', function (array $invalid) {
    $job = managedJob(jobManager());
    $job->fill($invalid)->save();
})->with([
    [['ward_name' => '   ']], [['work_address' => '   ']],
    [['ward_name' => str_repeat('x', 151)]], [['work_address' => str_repeat('x', 501)]],
    [['ward_name' => '<b>Phường A</b>']], [['work_address' => '<script>bad()</script>']],
])->throws(ValidationException::class);

it('rejects a child location supplied as the province', function () {
    $job = managedJob(jobManager());
    $child = Location::create(['code' => '99999', 'name' => 'Xã thử nghiệm', 'parent_id' => $job->location_id]);
    $job->location_id = $child->id;
    $job->save();
})->throws(ValidationException::class);

it('requires both address fields in the create and edit forms', function () {
    $actor = jobManager();
    $job = managedJob($actor);
    $this->actingAs($actor);
    $data = array_intersect_key($job->getAttributes(), JobData::rules());
    $data['ward_name'] = '';
    $data['work_address'] = '';
    Livewire::test(CreateJob::class)->fillForm($data)->call('create')
        ->assertHasFormErrors(['ward_name' => 'required', 'work_address' => 'required']);
    Livewire::test(EditJob::class, ['record' => $job->id])->fillForm($data)->call('save')
        ->assertHasFormErrors(['ward_name' => 'required', 'work_address' => 'required']);
    expect(Job::count())->toBe(1)->and($job->fresh()->ward_name)->toBe('Phường thử nghiệm');
});

it('clears local address fields when the selected province changes', function () {
    $actor = jobManager();
    $job = managedJob($actor);
    $province = Location::create(['code' => '56', 'name' => 'Khánh Hòa']);
    $this->actingAs($actor);
    Livewire::test(EditJob::class, ['record' => $job->id])->set('data.location_id', $province->id)
        ->assertSet('data.ward_name', null)->assertSet('data.work_address', null);
});

it('saves trims and previews the full worksite separately from the company office', function () {
    $actor = jobManager();
    $job = managedJob($actor);
    $job->company->update(['address' => 'Địa chỉ trụ sở khác']);
    $this->actingAs($actor);
    Livewire::test(EditJob::class, ['record' => $job->id])->fillForm([
        'ward_name' => '  Phường thử nghiệm  ', 'work_address' => '  Số 12, đường A & B, tầng 3  ',
    ])->call('save')->assertHasNoFormErrors();
    $job->refresh();
    expect($job->workplaceAddress())->toBe('Số 12, đường A & B, tầng 3, Phường thử nghiệm, Hà Nội');
    $this->get('/app/tin-tuyen-dung/'.$job->id)->assertOk()
        ->assertSee($job->workplaceAddress())->assertDontSee('Địa chỉ trụ sở khác');
});

it('copies the complete work address when duplicating a job', function () {
    $actor = jobManager();
    $source = publishManagedJob(managedJob($actor));
    $copy = app(ManageJobs::class)->duplicate($actor, $source);
    expect($copy->ward_name)->toBe($source->ward_name)->and($copy->work_address)->toBe($source->work_address)
        ->and($copy->location_id)->toBe($source->location_id)->and($copy->status)->toBe(JobStatus::Draft);
});

it('rejects work address changes while paused', function (string $field) {
    $job = publishManagedJob(managedJob(jobManager()));
    $job->transitionTo(JobStatus::Paused);
    $job->{$field} = 'Địa chỉ thay đổi';
    $job->save();
})->with(['ward_name', 'work_address'])->throws(ValidationException::class);

it('retains legacy jobs without inventing an address and requires completion before draft submission', function () {
    $actor = jobManager();
    $job = managedJob($actor);
    DB::table('jobs')->where('id', $job->id)->update(['ward_name' => null, 'work_address' => null, 'expires_at' => now()->addDays(7)]);
    $job->refresh();
    expect($job->workplaceAddress())->toBeNull();
    $this->actingAs($actor)->get('/app/tin-tuyen-dung/'.$job->id)->assertOk()->assertSee(__('ui.jobs.address_missing'));
    expect(fn () => app(SubmitJob::class)->submit($actor, $job))->toThrow(ValidationException::class);
    expect($job->fresh()->status)->toBe(JobStatus::Draft);
});

it('keeps legacy published jobs pausable without modifying their missing address', function () {
    $actor = jobManager();
    $job = publishManagedJob(managedJob($actor));
    DB::table('jobs')->where('id', $job->id)->update(['ward_name' => null, 'work_address' => null]);
    app(ManageJobs::class)->changeState($actor, $job, 'pause');
    expect($job->fresh()->status)->toBe(JobStatus::Paused)->and($job->fresh()->workplaceAddress())->toBeNull();
});

it('warns when duplicating a legacy job with no work address', function () {
    $actor = jobManager();
    $job = managedJob($actor);
    DB::table('jobs')->where('id', $job->id)->update(['ward_name' => null, 'work_address' => null]);
    $this->actingAs($actor);
    Livewire::test(ListJobs::class)->callAction(TestAction::make('duplicate')->table($job))
        ->assertNotified(__('ui.jobs.address_missing'));
    expect(Job::count())->toBe(1);
});

it('enforces complete address pairs in PostgreSQL while permitting legacy null pairs', function () {
    $job = managedJob(jobManager());
    DB::table('jobs')->where('id', $job->id)->update(['ward_name' => null]);
})->throws(QueryException::class);

it('screens contact details placed in either work address field', function (string $field) {
    $actor = jobManager();
    $job = managedJob($actor);
    $job->{$field} = 'Liên hệ 0901234567';
    $job->expires_at = now()->addDays(7);
    $job->save();
    $result = app(SubmitJob::class)->submit($actor, $job);
    expect($result->status)->toBe(JobStatus::Pending)
        ->and($result->content_scan_reasons)->toContain(['field' => $field, 'rule' => 'phone']);
})->with(['ward_name', 'work_address']);
