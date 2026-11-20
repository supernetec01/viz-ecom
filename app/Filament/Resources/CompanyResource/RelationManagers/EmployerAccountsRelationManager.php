<?php

namespace App\Filament\Resources\CompanyResource\RelationManagers;

use App\Enums\Role;
use App\Enums\UserStatus;
use App\Models\Company;
use App\Models\User;
use App\Services\EmployerAccounts;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class EmployerAccountsRelationManager extends RelationManager
{
    protected static string $relationship = 'employers';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('ui.employers.title');
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Company && Gate::allows('update', $ownerRecord);
    }

    public function table(Table $table): Table
    {
        return $table->modifyQueryUsing(fn (Builder $query) => $query->where('role', Role::Employer->value))->columns([
            TextColumn::make('name')->label(__('ui.employers.name')),
            TextColumn::make('email')->label(__('ui.auth.email')),
            TextColumn::make('status')->label(__('ui.employers.status'))->formatStateUsing(fn ($state) => __('ui.employers.states.'.$state->value)),
        ])->headerActions([
            Action::make('inviteEmployer')->label(__('ui.employers.create'))->authorize(fn (): bool => Gate::allows('update', $this->company()))
                ->schema([
                    TextInput::make('name')->label(__('ui.employers.name'))->required()->maxLength(255),
                    TextInput::make('email')->label(__('ui.auth.email'))->required()->email()->maxLength(255)
                        ->mutateStateForValidationUsing(fn (?string $state) => Str::lower(trim($state ?? '')))->unique(table: User::class, column: 'email'),
                ])->action(function (array $data): void {
                    app(EmployerAccounts::class)->create($this->actor(), $this->company(), $data);
                }),
        ])->recordActions([
            Action::make('resend')->label(__('ui.employers.resend'))->authorize(fn (): bool => Gate::allows('update', $this->company()))
                ->visible(fn (User $record): bool => $record->status === UserStatus::Active)
                ->action(function (User $record): void {
                    app(EmployerAccounts::class)->resend($this->actor(), $this->company(), $record);
                }),
            Action::make('lock')->label(__('ui.employers.lock'))->authorize(fn (): bool => Gate::allows('update', $this->company()))
                ->visible(fn (User $record): bool => $record->status === UserStatus::Active)->requiresConfirmation()
                ->action(function (User $record): void {
                    app(EmployerAccounts::class)->setStatus($this->actor(), $this->company(), $record, UserStatus::Locked);
                }),
            Action::make('unlock')->label(__('ui.employers.unlock'))->authorize(fn (): bool => Gate::allows('update', $this->company()))
                ->visible(fn (User $record): bool => $record->status === UserStatus::Locked)->requiresConfirmation()
                ->action(function (User $record): void {
                    app(EmployerAccounts::class)->setStatus($this->actor(), $this->company(), $record, UserStatus::Active);
                }),
        ]);
    }

    private function company(): Company
    {
        $company = $this->getOwnerRecord();
        abort_unless($company instanceof Company, 404);

        return $company;
    }

    private function actor(): User
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return $actor;
    }
}
