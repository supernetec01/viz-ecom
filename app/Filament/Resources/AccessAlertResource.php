<?php

namespace App\Filament\Resources;

use App\Filament\Resources\AccessAlertResource\Pages\ListAccessAlerts;
use App\Models\AccessAlert;
use App\Support\VietnamTime;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** @extends resource<AccessAlert> */
class AccessAlertResource extends Resource
{
    protected static ?string $model = AccessAlert::class;

    protected static ?string $slug = 'canh-bao-truy-cap';

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('security.alert_title');
    }

    public static function getPluralModelLabel(): string
    {
        return __('security.alert_title');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', AccessAlert::class);
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

    /** @return Builder<AccessAlert> */
    public static function getEloquentQuery(): Builder
    {
        Gate::authorize('viewAny', AccessAlert::class);

        return parent::getEloquentQuery()->select(['id', 'actor_id', 'actor_role', 'company_id', 'bucket', 'limit', 'created_at']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('actor_id')->label(__('security.actor')),
            TextColumn::make('actor_role')->label(__('security.role'))->formatStateUsing(fn (string $state) => __('applications.roles.'.$state)),
            TextColumn::make('company_id')->label(__('security.company')),
            TextColumn::make('bucket')->label(__('security.bucket'))->formatStateUsing(fn (string $state) => __('security.'.$state)),
            TextColumn::make('limit')->label(__('security.limit')),
            TextColumn::make('created_at')->label(__('security.time'))->formatStateUsing(fn ($state) => VietnamTime::format($state)),
        ])->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)
            ->recordUrl(null)->recordAction(null)->recordActions([])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAccessAlerts::route('/')];
    }
}
