<?php

namespace App\Filament\Resources;

use App\Enums\JobStatus;
use App\Filament\Resources\AccessRequestResource\Pages\ListAccessRequests;
use App\Models\Job;
use App\Models\User;
use App\Services\RequestApplicationAccess;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ViewColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/** @extends resource<Job> */
class AccessRequestResource extends Resource
{
    protected static ?string $model = Job::class;

    protected static ?string $slug = 'mo-ho-so';

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('orders.open_access');
    }

    public static function getPluralModelLabel(): string
    {
        return __('orders.open_access');
    }

    public static function canViewAny(): bool
    {
        $actor = auth()->user();

        return $actor instanceof User && app(RequestApplicationAccess::class)->allowed($actor);
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
        $actor = auth()->user();
        abort_unless($actor instanceof User, 403);

        return app(RequestApplicationAccess::class)->jobs($actor);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('title')->label(__('orders.job'))->wrap(),
            TextColumn::make('company_name')->label(__('orders.company'))->wrap(),
            TextColumn::make('status')->label(__('orders.job_status'))->formatStateUsing(fn (JobStatus $state): string => __('ui.jobs.states.'.$state->value)),
            ViewColumn::make('request_access')->label(__('orders.open_access'))->view('orders.request-button'),
        ])->defaultSort('jobs.id', 'desc')->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)
            ->recordUrl(null)->recordAction(null)->recordActions([])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListAccessRequests::route('/')];
    }
}
