<?php

namespace App\Filament\Resources;

use App\Filament\Resources\PaymentConfirmationResource\Pages\ListPaymentConfirmations;
use App\Models\Order;
use App\Support\VietnamTime;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** @extends resource<Order> */
class PaymentConfirmationResource extends Resource
{
    protected static ?string $model = Order::class;

    protected static ?string $slug = 'xac-nhan-thanh-toan';

    protected static bool $isGloballySearchable = false;

    public static function getModelLabel(): string
    {
        return __('payments.title');
    }

    public static function getPluralModelLabel(): string
    {
        return __('payments.title');
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

    /** @return Builder<Order> */
    public static function getEloquentQuery(): Builder
    {
        Gate::authorize('viewAny', Order::class);

        return Order::query()->where('type', 'access_grant')
            ->select(['id', 'company_id', 'job_id', 'amount', 'method', 'status', 'payment_reference', 'created_at'])
            ->with(['company:id,name', 'job:id,title']);
    }

    public static function table(Table $table): Table
    {
        return $table->columns([
            TextColumn::make('id')->label(__('orders.order_code'))->sortable(),
            TextColumn::make('company.name')->label(__('orders.company'))->placeholder(__('payments.unavailable_scope')),
            TextColumn::make('job.title')->label(__('orders.job'))->wrap()->placeholder(__('payments.unavailable_scope')),
            TextColumn::make('amount')->label(__('orders.amount'))->formatStateUsing(fn ($state): string => number_format($state, 0, ',', '.').' VND'),
            TextColumn::make('status')->label(__('payments.status'))->formatStateUsing(fn (string $state): string => __('payments.states.'.$state)),
            TextColumn::make('method')->label(__('payments.method'))->formatStateUsing(fn (string $state): string => __('payments.methods.'.$state)),
            TextColumn::make('payment_reference')->label(__('orders.reference')),
            TextColumn::make('created_at')->label(__('payments.created_at'))->formatStateUsing(fn ($state): string => VietnamTime::format($state)),
        ])->defaultSort('id', 'desc')->paginationPageOptions([10, 25, 50])->defaultPaginationPageOption(10)
            ->recordUrl(fn (Order $record) => route('payments.show', ['id' => $record->id]))->recordAction(null)->recordActions([])->toolbarActions([]);
    }

    public static function getPages(): array
    {
        return ['index' => ListPaymentConfirmations::route('/')];
    }
}
