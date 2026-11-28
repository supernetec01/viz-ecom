<?php

namespace App\Filament\Resources;

use App\Filament\Resources\JobBoostResource\Pages\ListRecordsPage;
use App\Models\Company;
use App\Models\Job;
use App\Models\JobBoost;
use App\Models\User;
use App\Support\VietnamTime;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** @extends resource<JobBoost> */
class JobBoostResource extends Resource
{
    protected static ?string $model = JobBoost::class;

    protected static ?string $slug = 'goi-da-gan';

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('boosts.assigned');
    }

    public static function getPluralModelLabel(): string
    {
        return __('boosts.assigned');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', Job::class);
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

    /** @return Builder<JobBoost> */
    public static function getEloquentQuery(): Builder
    {
        Gate::authorize('viewAny', Job::class);

        return JobBoost::query()->whereIn('company_id', Company::visibleTo(self::actor())->select('companies.id'))->with('job:id,title');
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label(__('boosts.code')),
            TextColumn::make('job.title')->label(__('orders.job'))->wrap(),
            TextColumn::make('order_id')->label(__('orders.order_code')),
            TextColumn::make('priority')->label(__('boosts.priority')),
            TextColumn::make('starts_at')->label(__('boosts.starts'))->formatStateUsing(fn ($state): string => VietnamTime::format($state)),
            TextColumn::make('ends_at')->label(__('boosts.ends'))->formatStateUsing(fn ($state): string => VietnamTime::format($state)),
        ])->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)
            ->recordUrl(fn (JobBoost $record) => route('boost-orders.show', ['id' => $record->order_id]))->recordAction(null)->recordActions([])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListRecordsPage::route('/')];
    }

    private static function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
