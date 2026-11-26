<?php

namespace App\Filament\Resources;

use App\Filament\Resources\ApplicationResource\Pages\ListApplications;
use App\Models\Application;
use App\Models\User;
use App\Policies\ApplicationPolicy;
use App\Support\VietnamTime;
use Filament\Forms\Components\DatePicker;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** @extends resource<Application> */
class ApplicationResource extends Resource
{
    protected static ?string $model = Application::class;

    protected static ?string $slug = 'ho-so';

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('applications.summary_singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('applications.summary_title');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', Application::class);
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(Model $record): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function canDelete(Model $record): bool
    {
        return false;
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }

    /** @return Builder<Application> */
    public static function getEloquentQuery(): Builder
    {
        return app(ApplicationPolicy::class)->employerSummaryQuery(self::actor());
    }

    public static function table(Table $table): Table
    {
        $policy = app(ApplicationPolicy::class);

        return $table->columns([
            TextColumn::make('full_name')->label(__('applications.fields.full_name'))->sortable(query: fn (Builder $query, string $direction) => $query->orderBy('full_name', $direction)),
            TextColumn::make('job_title')->label(__('applications.summary_job'))->wrap()->sortable(),
            TextColumn::make('submitted_at')->label(__('applications.summary_submitted'))->formatStateUsing(fn ($state) => VietnamTime::format($state))->sortable(),
        ])->searchable()->searchUsing(fn (Builder $query, string $search) => $policy->searchSummaries($query, $search))
            ->searchPlaceholder(__('applications.summary_search'))->searchDebounce('500ms')
            ->filters([
                SelectFilter::make('job_id')->label(__('applications.summary_job'))->options(fn () => $policy->employerSummaryJobs(self::actor()))
                    ->query(fn (Builder $query, array $data) => $policy->filterSummaryJob($query, $data['value'] ?? null)),
                Filter::make('submitted')->label(__('applications.summary_dates'))->schema([
                    DatePicker::make('from')->label(__('applications.summary_from'))->native(),
                    DatePicker::make('until')->label(__('applications.summary_until'))->native(),
                ])->query(fn (Builder $query, array $data) => $policy->filterSummaryDates($query, $data))
                    ->indicateUsing(function (array $data): array {
                        $indicators = [];
                        foreach (['from' => 'summary_from', 'until' => 'summary_until'] as $field => $label) {
                            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                                $indicators[] = Indicator::make(__('applications.'.$label).': '.$data[$field])->removeField($field);
                            }
                        }

                        return $indicators;
                    }),
            ])->defaultSort(fn (Builder $query) => $query->orderByDesc('applications.submitted_at')->orderByDesc('applications.id'))
            ->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)
            ->recordUrl(null)->recordAction(null)->recordActions([])->toolbarActions([])
            ->emptyStateHeading(__('applications.summary_empty'));
    }

    public static function getPages(): array
    {
        return ['index' => ListApplications::route('/')];
    }
}
