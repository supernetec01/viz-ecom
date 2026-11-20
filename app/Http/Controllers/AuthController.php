<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class AuthController extends Controller
{
    public function login(): RedirectResponse
    {
        return redirect()->route('login');
    }

    public function security(Request $request): View
    {
        return view('auth.security', ['user' => $request->user()]);
    }
}
