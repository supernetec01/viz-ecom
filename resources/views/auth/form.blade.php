@extends('auth.layout')
@section('content')
<h1>{{ __('ui.auth.'.$mode) }}</h1>
<form method="post" action="{{ $action }}">@csrf
@if(in_array($mode, ['login','forgot','reset']))<label for="email">{{ __('ui.auth.email') }}</label><input id="email" name="email" type="email" value="{{ old('email', $email ?? '') }}" required autocomplete="username">@endif
@if(in_array($mode, ['login','confirm','reset']))<label for="password">{{ __('ui.auth.password') }}</label><input id="password" name="password" type="password" required autocomplete="{{ $mode === 'reset' ? 'new-password' : 'current-password' }}">@endif
@if($mode === 'reset')<input type="hidden" name="token" value="{{ $token }}"><label for="password_confirmation">{{ __('ui.auth.password_confirmation') }}</label><input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">@endif
@if($mode === 'two_factor')<label for="code">{{ __('ui.auth.code') }}</label><input id="code" name="code" inputmode="numeric" autocomplete="one-time-code"><label for="recovery_code">{{ __('ui.auth.recovery_code') }}</label><input id="recovery_code" name="recovery_code" autocomplete="off">@endif
@if($mode === 'login')<label><input type="checkbox" name="remember" value="1"> {{ __('ui.auth.remember') }}</label>@endif
<button type="submit">{{ __('ui.auth.'.$mode) }}</button></form>
@if($mode === 'login')<p><a href="{{ route('password.request') }}">{{ __('ui.auth.forgot') }}</a></p>@endif
@endsection
