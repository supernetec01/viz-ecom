<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function areaUser(string $role): User
{
    return User::factory()->create(['role' => $role, 'two_factor_secret' => encrypt('JBSWY3DPEHPK3PXP'), 'two_factor_confirmed_at' => now()]);
}

it('rejects other roles with 403', function (string $role, string $area) {
    $this->actingAs(areaUser($role))->get('/khu-vuc/'.$area)->assertForbidden();
})->with([
    ['admin', 'staff'], ['admin', 'employer'], ['staff', 'admin'],
    ['staff', 'employer'], ['employer', 'admin'], ['employer', 'staff'],
]);

it('redirects guests to login', function (string $area) {
    $this->get('/khu-vuc/'.$area)->assertRedirect('/app/login');
})->with(['admin', 'staff', 'employer']);

it('allows each role only in its own area', function (string $role) {
    $user = areaUser($role);
    $this->actingAs($user)->get('/khu-vuc/'.$role)->assertOk();
})->with(['admin', 'staff', 'employer']);

it('renders Vietnamese 403 and 404 pages without leaking data', function () {
    app()->setLocale('vi');
    $this->actingAs(User::factory()->create())->get('/khu-vuc/admin')->assertForbidden()->assertSee('Bạn không có quyền truy cập.');
    $this->get('/khong-ton-tai')->assertNotFound()->assertSee('Không tìm thấy trang.');
});
