<?php

use App\Http\Controllers\ApplicationSubmissionController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\CompanyController;
use App\Http\Controllers\PublicCompanyController;
use App\Http\Controllers\PublicJobController;
use App\Http\Controllers\PublicJobDetailController;
use App\Http\Controllers\SitemapController;
use App\Http\Middleware\CaptureApplicationSource;
use App\Support\PublicSeo;
use Illuminate\Support\Facades\Route;

Route::view('/', 'public.home')->middleware(CaptureApplicationSource::class)->name('home');
Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap.index');
Route::get('/sitemaps/pages.xml', [SitemapController::class, 'pages'])->name('sitemap.pages');
Route::get('/sitemaps/{type}/{page}.xml', [SitemapController::class, 'entries'])->where('type', 'jobs|companies')->where('page', '[1-9][0-9]*')->name('sitemap.entries');
Route::get('/robots.txt', fn () => response("User-agent: *\nDisallow: /app\nDisallow: /noi-bo/\nDisallow: /khu-vuc/\nSitemap: ".PublicSeo::url('sitemap.index')."\n", 200, ['Content-Type' => 'text/plain; charset=UTF-8']));
Route::get('/viec-lam', PublicJobController::class)->middleware(CaptureApplicationSource::class)->name('jobs.index');
Route::get('/cong-ty/{id}', PublicCompanyController::class)->middleware(CaptureApplicationSource::class)->where('id', '[1-9][0-9]*')->name('companies.show');
Route::get('/viec-lam/{slug}-{id}', PublicJobDetailController::class)->middleware(CaptureApplicationSource::class)->where('slug', '[a-z0-9-]+')->where('id', '[1-9][0-9]*')->name('jobs.show');
Route::post('/ung-tuyen/{id}', ApplicationSubmissionController::class)->middleware('throttle:applications')->where('id', '[1-9][0-9]*')->name('applications.store');
Route::view('/chinh-sach-du-lieu', 'public.data-policy')->name('data-policy');

foreach (['admin', 'staff', 'employer'] as $role) {
    Route::view('/khu-vuc/'.$role, 'areas.index', ['role' => $role])
        ->middleware(['auth', 'role:'.$role])->name('areas.'.$role);
}

Route::get('/noi-bo/cong-ty/{company}', [CompanyController::class, 'show'])
    ->middleware(['auth', 'role:admin,staff,employer']);

Route::get('/bao-mat', [AuthController::class, 'security'])
    ->middleware(['auth', 'password.confirm'])->name('security');
