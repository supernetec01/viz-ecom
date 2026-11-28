<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BoostJobResource\Pages\ListRecordsPage;
use App\Models\Job;
use App\Models\User;
use App\Services\BoostOrders;
use App\Support\VietnamTime;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** @extends resource<Job> */
class BoostJobResource extends Resource
{
    protected static ?string $model = Job::class;

    protected static ?string $slug = 'day-tin';

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('boosts.jobs');
    }

    public static function getPluralModelLabel(): string
    {
        return __('boosts.jobs');
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

    /** @return Builder<Job> */
    public static function getEloquentQuery(): Builder
    {
        Gate::authorize('viewAny', Job::class);

        return app(BoostOrders::class)->jobs(self::actor());
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label(__('orders.job'))->wrap(),
            TextColumn::make('company.name')->label(__('orders.company')),
            TextColumn::make('expires_at')->label(__('ui.jobs.expires_at'))->formatStateUsing(fn ($state): string => VietnamTime::format($state)),
        ])->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)
            ->recordUrl(fn (Job $record) => route('boost-orders.request', ['id' => $record->id]))->recordAction(null)->recordActions([])->toolbarActions([]);
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
