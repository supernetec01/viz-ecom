<?php

namespace App\Filament\Resources;

use App\Filament\Resources\FileReviewResource\Pages\ListFileReviews;
use App\Models\CandidateFile;
use App\Models\User;
use App\Policies\ApplicationPolicy;
use App\Services\RetryFileProcessing;
use App\Support\FileReadiness;
use App\Support\VietnamTime;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Resource;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;

/** @extends resource<CandidateFile> */
class FileReviewResource extends Resource
{
    protected static ?string $model = CandidateFile::class;

    protected static ?string $slug = 'file-can-xem-tay';

    public static function getModelLabel(): string
    {
        return __('files.singular');
    }

    public static function getPluralModelLabel(): string
    {
        return __('files.manual_review');
    }

    public static function canViewAny(): bool
    {
        return Gate::allows('viewAny', CandidateFile::class);
    }

    public static function canCreate(): bool
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

    /** @return Builder<CandidateFile> */
    public static function getEloquentQuery(): Builder
    {
        return parent::getEloquentQuery()->select(['id', 'scan_status', 'preview_status', 'review_required', 'processing_attempts',
            'scan_attempts', 'preview_attempts', 'scan_error_code', 'preview_error_code', 'scanned_at', 'updated_at'])
            ->when(! static::canViewAny(), fn (Builder $query) => $query->whereRaw('1 = 0'));
    }

    public static function table(Table $table): Table
    {
        return $table->defaultSort('updated_at', 'desc')->columns([
            TextColumn::make('id')->label(__('files.file_id'))->sortable(),
            TextColumn::make('application_ids')->label(__('files.application_id'))->state(function (CandidateFile $record): string {
                $actor = auth()->user();
                abort_unless($actor instanceof User, 403);

                return implode(', ', app(ApplicationPolicy::class)->fileApplicationIds($actor, $record->id));
            })->wrap(),
            TextColumn::make('scan_status')->label(__('files.scan_status')),
            TextColumn::make('preview_status')->label(__('files.preview_status')),
            TextColumn::make('processing_attempts')->label(__('files.attempts')),
            TextColumn::make('scan_attempts')->label(__('files.scan_attempts')),
            TextColumn::make('preview_attempts')->label(__('files.preview_attempts')),
            TextColumn::make('scan_error_code')->label(__('files.scan_error_code_label')),
            TextColumn::make('preview_error_code')->label(__('files.preview_error')),
            TextColumn::make('state_label')->label(__('files.readiness'))->state(fn (CandidateFile $record) => FileReadiness::for($record)['message'])->wrap(),
            TextColumn::make('updated_at')->label(__('files.updated_at'))->formatStateUsing(fn ($state) => VietnamTime::format($state)),
        ])->filters([
            TernaryFilter::make('review_required')->label(__('files.manual_review'))->default(true),
            SelectFilter::make('scan_status')->label(__('files.scan_status'))->options(['pending' => 'pending', 'clean' => 'clean', 'infected' => 'infected', 'error' => 'error']),
            SelectFilter::make('preview_status')->label(__('files.preview_status'))->options(['pending' => 'pending', 'ready' => 'ready', 'failed' => 'failed']),
        ])->recordActions([
            Action::make('retry')->label(__('files.retry'))->authorize('retry')->visible(fn (CandidateFile $record) => $record->review_required && $record->scan_status !== 'infected')
                ->requiresConfirmation()->action(function (CandidateFile $record): void {
                    $actor = auth()->user();
                    abort_unless($actor instanceof User, 403);
                    app(RetryFileProcessing::class)->retry($actor, $record->id);
                    Notification::make()->success()->title(__('files.queued'))->send();
                }),
        ]);
    }

    public static function getPages(): array
    {
        return ['index' => ListFileReviews::route('/')];
    }
}
