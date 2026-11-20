<?php

use Laravel\Fortify\Features;

return [
    'guard' => 'web', 'middleware' => ['web'], 'auth_middleware' => 'auth',
    'passwords' => 'users', 'username' => 'email', 'email' => 'email',
    'views' => true, 'home' => '/app', 'lowercase_usernames' => true,
    'limiters' => ['login' => 'login', 'two-factor' => 'two-factor'],
    'features' => [Features::resetPasswords(), Features::twoFactorAuthentication([
        'confirm' => true, 'confirmPassword' => true, 'window' => 1,
    ])],
];
