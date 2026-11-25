<?php

namespace App\Filament\Widgets;

use App\Filament\Resources\FileReviewResource;
use App\Models\CandidateFile;
use Filament\Widgets\StatsOverviewWidget;
use Filament\Widgets\StatsOverviewWidget\Stat;
use Illuminate\Support\Facades\Gate;

class FileReviewStats extends StatsOverviewWidget
{
    public static function canView(): bool
    {
        return Gate::allows('viewAny', CandidateFile::class);
    }

    public function mount(): void
    {
        abort_unless(static::canView(), 403);
    }

    protected function getStats(): array
    {
        abort_unless(static::canView(), 403);

        return [Stat::make(__('files.manual_review'), CandidateFile::where('review_required', true)->count())
            ->url(FileReviewResource::getUrl('index'))];
    }
}
