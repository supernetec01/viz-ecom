<?php

namespace Tests;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\EmployerSessions;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function actingAs(Authenticatable $user, $guard = null)
    {
        parent::actingAs($user, $guard);
        // actingAs bypasses the Login event; give fixtures a genuine registry slot.
        if ($user instanceof User && $user->fresh()?->status === UserStatus::Active) {
            $request = request();
            $request->setLaravelSession(app('session.store'));
            app(EmployerSessions::class)->register($user, $request);
        }

        return $this;
    }
}
