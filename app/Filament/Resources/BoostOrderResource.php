<?php

namespace App\Filament\Resources;

use App\Filament\Resources\BoostOrderResource\Pages\ListRecordsPage;
use App\Models\Company;
use App\Models\Job;
use App\Models\Order;
use App\Models\User;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** @extends resource<Order> */
class BoostOrderResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $slug = 'don-day-tin';

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('boosts.orders');
    }

    public static function getPluralModelLabel(): string
    {
        return __('boosts.orders');
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

    /** @return Builder<Order> */
    public static function getEloquentQuery(): Builder
    {
        Gate::authorize('viewAny', Job::class);

        return Order::query()->where('type', 'job_boost')->whereIn('company_id', Company::visibleTo(self::actor())->select('companies.id'))->with(['company:id,name', 'job:id,title']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label(__('orders.order_code')),
            TextColumn::make('company.name')->label(__('orders.company')),
            TextColumn::make('job.title')->label(__('orders.job'))->wrap(),
            TextColumn::make('boost_plan_name')->label(__('boosts.plan')),
            TextColumn::make('amount')->label(__('orders.amount'))->numeric(decimalPlaces: 0),
            TextColumn::make('status')->label(__('payments.status'))->formatStateUsing(fn (string $state): string => __('payments.states.'.$state)),
        ])->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)
            ->recordUrl(fn (Order $record) => route('boost-orders.show', ['id' => $record->id]))->recordAction(null)->recordActions([])->toolbarActions([]);
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
