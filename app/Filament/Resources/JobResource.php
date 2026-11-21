<?php

namespace App\Filament\Resources;

use App\Enums\JobStatus;
use App\Filament\Resources\JobResource\Pages;
use App\Models\Company;
use App\Models\Job;
use App\Models\JobCategory;
use App\Models\Location;
use App\Models\User;
use App\Services\ManageJobs;
use App\Services\SubmitJob;
use App\Support\JobHtml;
use App\Support\JobScanSummary;
use App\Support\VietnamTime;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/** @extends resource<Job> */
class JobResource extends Resource
{
    protected static ?string $model = Job::class;

    protected static ?string $slug = 'tin-tuyen-dung';

    public static function getModelLabel(): string
    {
        return __('ui.jobs.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.jobs.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            Select::make('company_id')->label(__('ui.jobs.company'))->required()->searchable()
                ->options(fn () => Company::visibleTo(self::actor())->whereNotNull('verified_at')->whereNotNull('verified_by')->pluck('name', 'id'))
                ->disabledOn('edit')->dehydrated(fn (string $operation): bool => $operation !== 'edit'),
            TextInput::make('title')->label(__('ui.jobs.title'))->required()->maxLength(255),
            Select::make('category_id')->label(__('ui.jobs.category'))->required()->searchable()->options(fn () => JobCategory::pluck('name', 'id')),
            Select::make('location_id')->label(__('ui.jobs.location'))->required()->searchable()->live()
                ->options(fn () => Location::whereNull('parent_id')->pluck('name', 'id'))
                ->afterStateUpdated(function (Set $set): void {
                    $set('ward_name', null);
                    $set('work_address', null);
                }),
            TextInput::make('ward_name')->label(__('ui.jobs.ward_name'))->required()->maxLength(150)->rules(['regex:/^[^<>]*$/u'])
                ->helperText(__('ui.jobs.ward_help')),
            TextInput::make('work_address')->label(__('ui.jobs.work_address'))->required()->maxLength(500)->rules(['regex:/^[^<>]*$/u'])
                ->helperText(__('ui.jobs.address_help')),
            TextInput::make('salary_min')->label(__('ui.jobs.salary_min'))->integer()->minValue(0)->maxValue(PHP_INT_MAX)->suffix('VND'),
            TextInput::make('salary_max')->label(__('ui.jobs.salary_max'))->integer()->minValue(0)->maxValue(PHP_INT_MAX)->suffix('VND')
                ->rules(fn (Get $get): array => filled($get('salary_min')) ? ['gte:data.salary_min'] : []),
            Toggle::make('negotiable')->label(__('ui.jobs.negotiable'))->default(false),
            TextInput::make('experience_years')->label(__('ui.jobs.experience_years'))->required()->integer()->minValue(0)->maxValue(2147483647)->default(0),
            TextInput::make('vacancies')->label(__('ui.jobs.vacancies'))->required()->integer()->minValue(1)->maxValue(2147483647)->default(1),
            Select::make('employment_type')->label(__('ui.jobs.employment_type'))->required()->options(__('ui.jobs.employment_types'))->default('full_time'),
            DateTimePicker::make('expires_at')->label(__('ui.jobs.expires_at'))->timezone(config('app.display_timezone'))->seconds(false),
            ...array_map(fn (string $field) => RichEditor::make($field)->label(__('ui.jobs.'.$field))->required()->maxLength(50000)->columnSpanFull()->fileAttachments(false)
                ->toolbarButtons(['h2', 'h3', 'bold', 'italic', 'underline', 'strike', 'bulletList', 'orderedList', 'blockquote', 'link', 'undo', 'redo']), ['description', 'requirements', 'benefits']),
        ]);
    }

    public static function infolist(Schema $schema): Schema
    {
        return $schema->components([
            TextEntry::make('title')->label(__('ui.jobs.title'))->columnSpanFull(),
            TextEntry::make('company.name')->label(__('ui.jobs.company')),
            TextEntry::make('status')->label(__('ui.jobs.status'))->formatStateUsing(fn (JobStatus $state): string => __('ui.jobs.states.'.$state->value)),
            TextEntry::make('category.name')->label(__('ui.jobs.category')),
            TextEntry::make('workplace_address')->label(__('ui.jobs.workplace'))
                ->state(fn (Job $record): ?string => $record->workplaceAddress())->placeholder(__('ui.jobs.address_missing'))->columnSpanFull(),
            TextEntry::make('scan_summary')->label(__('ui.review.scan_reasons'))->state(fn (Job $record): string => JobScanSummary::for($record))
                ->visible(fn (Job $record): bool => $record->status === JobStatus::Pending && Gate::allows('reviewAny', Job::class))->columnSpanFull(),
            TextEntry::make('latestReview.decision')->label(__('ui.review.latest_decision'))
                ->formatStateUsing(fn (string $state): string => __('ui.review.'.$state))->placeholder('—'),
            TextEntry::make('latestReview.reason')->label(__('ui.review.reason'))->placeholder('—')->columnSpanFull(),
            TextEntry::make('salary_min')->label(__('ui.jobs.salary_min'))->numeric(decimalPlaces: 0)->suffix(' VND')->placeholder('—'),
            TextEntry::make('salary_max')->label(__('ui.jobs.salary_max'))->numeric(decimalPlaces: 0)->suffix(' VND')->placeholder('—'),
            TextEntry::make('negotiable')->label(__('ui.jobs.negotiable'))->formatStateUsing(fn (bool $state): string => $state ? __('ui.jobs.yes') : __('ui.jobs.no')),
            TextEntry::make('experience_years')->label(__('ui.jobs.experience_years')),
            TextEntry::make('vacancies')->label(__('ui.jobs.vacancies')),
            TextEntry::make('employment_type')->label(__('ui.jobs.employment_type'))->formatStateUsing(fn ($state): string => __('ui.jobs.employment_types.'.$state->value)),
            TextEntry::make('published_at')->label(__('ui.jobs.published_at'))->formatStateUsing(fn ($state): string => VietnamTime::format($state))->placeholder('—'),
            TextEntry::make('expires_at')->label(__('ui.jobs.expires_at'))->formatStateUsing(fn ($state): string => VietnamTime::format($state))->placeholder('—'),
            ...array_map(fn (string $field) => TextEntry::make($field)->label(__('ui.jobs.'.$field))->html()
                ->formatStateUsing(fn (string $state): string => JobHtml::clean($state))->columnSpanFull(), ['description', 'requirements', 'benefits']),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('id', 'desc')->columns([
            TextColumn::make('title')->label(__('ui.jobs.title'))->searchable()->sortable()->limit(50)->wrap(),
            TextColumn::make('company.name')->label(__('ui.jobs.company'))->limit(35)->wrap(),
            TextColumn::make('status')->label(__('ui.jobs.status'))->badge()->formatStateUsing(fn (JobStatus $state): string => __('ui.jobs.states.'.$state->value)),
            TextColumn::make('expires_at')->label(__('ui.jobs.expires_at'))->formatStateUsing(fn ($state): string => VietnamTime::format($state)),
        ])->filters([SelectFilter::make('status')->label(__('ui.jobs.status'))->options(__('ui.jobs.states'))])
            ->recordActions([
                ViewAction::make()->label(__('ui.jobs.preview')),
                EditAction::make(),
                ActionGroup::make([
                    self::submissionAction(),
                    Action::make('duplicate')->label(__('ui.jobs.duplicate'))->authorize('replicate')->requiresConfirmation()
                        ->action(function (Job $record): void {
                            try {
                                app(ManageJobs::class)->duplicate(self::actor(), $record);
                            } catch (ValidationException $exception) {
                                if (array_intersect(array_keys($exception->errors()), ['ward_name', 'work_address']) === []) {
                                    throw $exception;
                                }
                                Notification::make()->warning()->title(__('ui.jobs.address_missing'))->body(__('ui.jobs.complete_before_duplicate'))->send();

                                return;
                            }
                            Notification::make()->success()->title(__('ui.jobs.duplicated'))->send();
                        }),
                    ...array_map(fn (string $action): Action => Action::make($action)->label(__('ui.jobs.'.$action))->authorize($action)->requiresConfirmation()
                        ->action(function (Job $record) use ($action): void {
                            $result = app(ManageJobs::class)->changeState(self::actor(), $record, $action);
                            $record->refresh();
                            if ($action === 'resume' && $result->status === JobStatus::Expired) {
                                Notification::make()->warning()->title(__('ui.jobs.expired_on_resume'))->send();
                            }
                        }), ['pause', 'resume', 'close', 'redraft']),
                ])->label(__('ui.jobs.actions'))->button(),
            ]);
    }

    /** @return Builder<Job> */
    public static function getEloquentQuery(): Builder
    {
        return Job::visibleTo(self::actor())->with(['company', 'category', 'location', 'latestReview']);
    }

    public static function submissionAction(): Action
    {
        return Action::make('submit')->label(__('ui.review.submit'))->authorize('submit')->requiresConfirmation()
            ->schema([DateTimePicker::make('expires_at')->label(__('ui.jobs.expires_at'))->required()->rules(['after:now'])
                ->timezone(config('app.display_timezone'))->seconds(false)->default(fn (Job $record) => $record->expires_at)])
            ->action(function (Job $record, array $data): void {
                try {
                    $result = app(SubmitJob::class)->submit(self::actor(), $record, $data);
                    $record->refresh();
                } catch (ValidationException $exception) {
                    if (array_intersect(array_keys($exception->errors()), ['ward_name', 'work_address']) === []) {
                        throw $exception;
                    }
                    Notification::make()->warning()->title(__('ui.jobs.address_missing'))->body(__('ui.review.complete_address'))->send();

                    return;
                }
                Notification::make()->success()->title(__($result->status === JobStatus::Pending ? 'ui.review.submitted_pending' : 'ui.review.submitted_published'))->send();
            });
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListJobs::route('/'), 'create' => Pages\CreateJob::route('/create'), 'view' => Pages\ViewJob::route('/{record}'), 'edit' => Pages\EditJob::route('/{record}/edit')];
    }

    private static function actor(): User
    {
        $user = auth()->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }
}
