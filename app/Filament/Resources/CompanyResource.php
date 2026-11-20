<?php

namespace App\Filament\Resources;

use App\Enums\ChannelType;
use App\Enums\Role;
use App\Filament\Resources\CompanyResource\Pages;
use App\Filament\Resources\CompanyResource\RelationManagers\EmployerAccountsRelationManager;
use App\Models\Company;
use App\Models\User;
use App\Rules\AllowedChannelUrl;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Resources\Resource;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;

/** @extends \Filament\Resources\Resource<Company> */
class CompanyResource extends Resource
{
    protected static ?string $model = Company::class;

    protected static ?string $slug = 'cong-ty';

    public static function getModelLabel(): string
    {
        return __('ui.companies.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('ui.companies.plural');
    }

    public static function form(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')->label(__('ui.companies.name'))->required()->maxLength(255),
            Select::make('type')->label(__('ui.companies.type'))->options(__('ui.companies.types'))->required()->default('direct'),
            TextInput::make('tax_code')->label(__('ui.companies.tax_code'))->maxLength(50),
            Textarea::make('address')->label(__('ui.companies.address')),
            Textarea::make('introduction')->label(__('ui.companies.introduction')),
            Textarea::make('industry')->label(__('ui.companies.industry')),
            Textarea::make('products')->label(__('ui.companies.products')),
            Select::make('staff')->label(__('ui.companies.staff'))->multiple()
                ->relationship('staff', 'name', modifyQueryUsing: fn (Builder $query) => $query->where('role', Role::Staff->value))
                ->visible(fn (): bool => auth()->user()?->role === Role::Admin)
                ->disabled(fn (): bool => auth()->user()?->role !== Role::Admin)
                ->saveRelationshipsWhenHidden(false)->saveRelationshipsWhenDisabled(false),
            Repeater::make('channels')->label(__('ui.channels.label'))->relationship()->defaultItems(0)->schema([
                Select::make('type')->label(__('ui.channels.type'))->options(collect(ChannelType::cases())->mapWithKeys(fn (ChannelType $type) => [$type->value => __('ui.channels.types.'.$type->value)])->all())->required()->live(),
                TextInput::make('display_name')->label(__('ui.channels.name'))->required()->maxLength(255),
                TextInput::make('url')->label(__('ui.channels.url'))->required()->maxLength(2048)
                    ->rules(['url:http,https', fn (Get $get) => new AllowedChannelUrl($get('type') ?? '')]),
            ])->columnSpanFull(),
        ]);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('name')->label(__('ui.companies.name'))->searchable()->sortable(),
            TextColumn::make('type')->label(__('ui.companies.type'))->formatStateUsing(fn ($state) => __('ui.companies.types.'.$state->value)),
            IconColumn::make('verified_at')->label(__('ui.companies.verified'))->getStateUsing(fn (Company $record): bool => $record->isVerified())->boolean(),
        ])->filters([SelectFilter::make('type')->label(__('ui.companies.type'))->options(__('ui.companies.types'))])
            ->recordActions([
                EditAction::make(),
                Action::make('verify')->label(__('ui.companies.verify'))->authorize('verify')
                    ->visible(fn (Company $record): bool => ! $record->isVerified())->requiresConfirmation()
                    ->action(function (Company $record): void {
                        Gate::authorize('verify', $record);
                        $record->forceFill(['verified_at' => now(), 'verified_by' => auth()->id()])->save();
                    }),
            ]);
    }

    /** @return Builder<Company> */
    public static function getEloquentQuery(): Builder
    {
        $user = auth()->user();

        return $user instanceof User ? Company::query()->visibleTo($user) : Company::query()->whereRaw('1 = 0');
    }

    public static function getPages(): array
    {
        return ['index' => Pages\ListCompanies::route('/'), 'create' => Pages\CreateCompany::route('/create'), 'edit' => Pages\EditCompany::route('/{record}/edit')];
    }

    public static function getRelations(): array
    {
        return [EmployerAccountsRelationManager::class];
    }
}
