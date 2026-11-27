<?php

namespace App\Providers;

use App\Contracts\PreviewConverter;
use App\Contracts\VirusScanner;
use App\Events\FileProcessingRetryRequested;
use App\Models\User;
use App\Services\ContentScanner;
use App\Services\DocumentSandbox;
use App\Services\RecordAudit;
use App\Services\RecordSuccessfulLogin;
use Illuminate\Auth\Events\Login;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(VirusScanner::class, DocumentSandbox::class);
        $this->app->bind(PreviewConverter::class, DocumentSandbox::class);
        $this->app->singleton(ContentScanner::class, fn () => new ContentScanner(config('app.url')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Event::listen(FileProcessingRetryRequested::class, function (FileProcessingRetryRequested $event): void {
            app(RecordAudit::class)->processingRetry(User::query()->findOrFail($event->actorId), $event->fileId, $event->stage, request());
            Log::info('candidate_file_retry_audit', ['actor_id' => $event->actorId, 'file_id' => $event->fileId,
                'stage' => $event->stage, 'occurred_at' => $event->occurredAt]);
        });
        if ($this->app->isProduction()) {
            URL::forceScheme('https');
        }

        RateLimiter::for('web', fn (Request $request) => Limit::perMinute(120)->by($request->ip()));
        RateLimiter::for('applications', fn (Request $request) => Limit::perMinute(10)->by($request->ip()));
        Event::listen(Login::class, function (Login $event): void {
            app(RecordSuccessfulLogin::class)->record($event, request());
        });
    }
}
