<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PlanResource\Pages;
use App\Models\Plan;
use Filament\Actions\EditAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** @extends resource<Plan> */
class PlanResource extends Resource
{
    protected static ?string $model = Plan::class;

    protected static ?string $slug = 'goi-day-tin';

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('boosts.plan');
    }

    public static function getPluralModelLabel(): string
    {
        return __('boosts.plans');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('boosts.name'))->required()->maxLength(255),
            TextInput::make('duration_days')->label(__('boosts.days'))->required()->integer()->minValue(1)->maxValue(366),
            TextInput::make('priority')->label(__('boosts.priority'))->required()->integer()->minValue(1)->maxValue(1000000),
            TextInput::make('price')->label(__('boosts.price'))->required()->integer()->minValue(1)->maxValue(PHP_INT_MAX),
            Toggle::make('is_active')->label(__('boosts.active'))->default(true),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('boosts.name')),
            TextColumn::make('duration_days')->label(__('boosts.days')),
            TextColumn::make('priority')->label(__('boosts.priority')),
            TextColumn::make('price')->label(__('boosts.price'))->numeric(decimalPlaces: 0),
            IconColumn::make('is_active')->label(__('boosts.active'))->boolean(),
        ])->recordUrl(fn (Plan $record) => Gate::allows('update', $record) ? self::getUrl('edit', ['record' => $record]) : null)
            ->recordAction(null)->recordActions([EditAction::make()])->defaultSort('id')->paginationPageOptions([10, 25, 50]);
    }

    /** @return Builder<Plan> */
    public static function getEloquentQuery(): Builder
    {
        Gate::authorize('viewAny', Plan::class);

        return Plan::query();
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListPlans::route('/'), 'create' => Pages\CreatePlan::route('/create'), 'edit' => Pages\EditPlan::route('/{record}/edit')];
    }
}
