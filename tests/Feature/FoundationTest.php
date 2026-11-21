<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('serves the public home page', function () {
    $this->get('/')->assertOk();
});

it('serves the health endpoint', function () {
    $this->get('/up')->assertOk();
});

it('runs the foundation migrations and persists records on PostgreSQL', function () {
    expect(DB::connection()->getDriverName())->toBe('pgsql');

    foreach (['users', 'password_reset_tokens', 'sessions', 'cache', config('queue.connections.database.table'), 'failed_jobs'] as $table) {
        expect(Schema::hasTable($table))->toBeTrue();
    }

    $user = User::factory()->create();

    $this->assertDatabaseHas('users', ['id' => $user->id, 'email' => $user->email]);
});
