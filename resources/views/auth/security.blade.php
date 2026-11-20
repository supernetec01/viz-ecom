@extends('auth.layout')
@section('content')
<h1>{{ __('ui.security') }}</h1>
@if(!$user->two_factor_secret)
<p>{{ __('ui.auth.enable_help') }}</p><form method="post" action="{{ route('two-factor.enable') }}">@csrf<button>{{ __('ui.auth.enable') }}</button></form>
@elseif(!$user->hasEnabledTwoFactorAuthentication())
<p>{{ __('ui.auth.scan_help') }}</p>{!! $user->twoFactorQrCodeSvg() !!}
<form method="post" action="{{ route('two-factor.confirm') }}">@csrf<label for="code">{{ __('ui.auth.code') }}</label><input id="code" name="code" required inputmode="numeric" autocomplete="one-time-code"><button>{{ __('ui.auth.confirm') }}</button></form>
@else
<p>{{ __('ui.auth.enabled') }}</p><p>{{ __('ui.auth.recovery_help') }}</p><pre>{{ implode("\n", $user->recoveryCodes()) }}</pre>
@endif
<p><a href="{{ url('/app') }}">{{ __('ui.auth.continue') }}</a></p>
<form method="post" action="{{ route('logout') }}">@csrf<button>{{ __('ui.logout') }}</button></form>
@endsection
