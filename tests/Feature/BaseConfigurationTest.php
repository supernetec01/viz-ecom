<?php

use App\Models\User;
use App\Support\VietnamTime;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('adds security headers to responses', function () {
    $this->get('/')->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeader('X-Frame-Options', 'DENY')
        ->assertHeader('Content-Security-Policy');
});

it('stores UTC and renders Vietnam time across midnight', function () {
    $user = User::factory()->create(['created_at' => '2026-11-20 23:30:00']);
    expect($user->fresh()->created_at->format('Y-m-d H:i'))->toBe('2026-11-20 23:30')
        ->and(VietnamTime::format($user->fresh()->created_at))->toBe('21/11/2026 06:30');
});

it('redirects production HTTP to HTTPS preserving the URL', function () {
    app()->instance('env', 'production');
    $this->get('http://localhost/example?trang=2')->assertStatus(308)->assertRedirect('https://localhost/example?trang=2');
});

it('uses Vietnamese translations', function () {
    app()->setLocale('vi');
    expect(__('ui.login'))->toBe('Đăng nhập');
});

it('limits repeated web requests', function () {
    for ($i = 0; $i < 120; $i++) {
        $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->get('/')->assertOk();
    }
    $this->withServerVariables(['REMOTE_ADDR' => '192.0.2.1'])->get('/')->assertStatus(429);
});
