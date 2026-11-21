<?php

namespace App\Filament\Resources;

use App\Enums\JobStatus;
use App\Filament\Resources\PendingJobResource\Pages\ListPendingJobs;
use App\Models\Job;
use App\Models\User;
use App\Services\ReviewJob;
use App\Support\JobScanSummary;
use App\Support\VietnamTime;
use Filament\Actions\Action;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** @extends resource<Job> */
class PendingJobResource extends Resource
{
    protected static ?string $model = Job::class;

    protected static ?string $slug = 'duyet-tin';

    public static function getModelLabel(): string
    {
        return __('ui.review.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.review.queue');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('reviewAny', Job::class);
    }

    public static function canView(Model $record): bool
    {
        return Gate::allows('review', $record);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    /** @return Builder<Job> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->where('status', JobStatus::Pending)
            ->when(! static::canViewAny(), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->with(['company', 'category', 'location', 'latestReview']);
    }

    public static function infolist(Schema $schema): Schema
    {
        return JobResource::infolist($schema);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id')->columns([
            TextColumn::make('title')->label(__('ui.jobs.title'))->searchable()->wrap(),
            TextColumn::make('company.name')->label(__('ui.jobs.company'))->wrap(),
            TextColumn::make('scan_summary')->label(__('ui.review.scan_reasons'))->state(fn (Job $record): string => JobScanSummary::for($record))->wrap(),
            TextColumn::make('content_scanned_at')->label(__('ui.review.scanned_at'))->formatStateUsing(fn ($state): string => VietnamTime::format($state)),
        ])->recordActions([
            ViewAction::make()->label(__('ui.jobs.preview')),
            Action::make('approve')->label(__('ui.review.approve'))->authorize('review')->requiresConfirmation()
                ->schema([DateTimePicker::make('expires_at')->label(__('ui.jobs.expires_at'))->required()->rules(['after:now'])
                    ->timezone(config('app.display_timezone'))->seconds(false)->default(fn (Job $record) => $record->expires_at)])
                ->action(function (Job $record, array $data): void {
                    try {
                        app(ReviewJob::class)->approve(self::actor(), $record, $data);
                    } catch (ValidationException $exception) {
                        Notification::make()->warning()->title(__('ui.review.cannot_approve'))
                            ->body(e(implode("\n", $exception->validator->errors()->all())))->send();

                        return;
                    }
                    Notification::make()->success()->title(__('ui.review.approved'))->send();
                }),
            Action::make('reject')->label(__('ui.review.reject'))->authorize('review')->color('danger')->requiresConfirmation()
                ->schema([Textarea::make('reason')->label(__('ui.review.reason'))->required()->maxLength(2000)->rules(['regex:/^[^<>]*$/u'])])
                ->action(function (Job $record, array $data): void {
                    app(ReviewJob::class)->reject(self::actor(), $record, $data);
                    Notification::make()->success()->title(__('ui.review.rejected'))->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPendingJobs::route('/')];
    }

    private static function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
