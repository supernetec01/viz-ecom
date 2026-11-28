<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccessGrantResource\Pages\ListAccessGrants;
use App\Models\AccessGrant;
use App\Models\Order;
use App\Support\VietnamTime;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** @extends resource<AccessGrant> */
class AccessGrantResource extends Resource
{
    protected static ?string $model = AccessGrant::class;

    protected static ?string $slug = 'quyen-xem-ho-so';

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('grants.title');
    }

    public static function getPluralModelLabel(): string
    {
        return __('grants.title');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', Order::class);
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

    /** @return Builder<AccessGrant> */
    public static function getEloquentQuery(): Builder
    {
        Gate::authorize('viewAny', Order::class);

        return AccessGrant::query()->select(['id', 'company_id', 'job_id', 'order_id', 'previous_grant_id', 'starts_at', 'ends_at', 'status', 'revoked_reason'])->with(['company:id,name', 'job:id,title']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label(__('grants.code'))->sortable(),
            TextColumn::make('company.name')->label(__('orders.company')),
            TextColumn::make('job.title')->label(__('orders.job'))->wrap(),
            TextColumn::make('order_id')->label(__('orders.order_code')),
            TextColumn::make('previous_grant_id')->label(__('grants.previous')),
            TextColumn::make('starts_at')->label(__('grants.start'))->formatStateUsing(fn ($state): string => VietnamTime::format($state)),
            TextColumn::make('ends_at')->label(__('grants.end'))->formatStateUsing(fn ($state): string => VietnamTime::format($state)),
            TextColumn::make('status')->label(__('grants.state'))->formatStateUsing(fn (string $state): string => __('grants.'.($state === 'revoked' ? 'revoked_state' : 'active'))),
            TextColumn::make('revoked_reason')->label(__('grants.reason'))->formatStateUsing(fn (string $state): string => __('grants.reasons.'.$state)),
        ])->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)
            ->recordUrl(fn (AccessGrant $record) => route('grants.show', ['id' => $record->id]))->recordAction(null)->recordActions([])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAccessGrants::route('/')];
    }
}
