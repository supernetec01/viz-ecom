<!doctype html>
<html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>{{ __('grants.manage') }}</title>@vite('resources/css/app.css')</head>
<body class="bg-slate-50 text-slate-900"><main class="mx-auto max-w-3xl space-y-6 p-6">
<a class="underline" href="{{ url('/app/quyen-xem-ho-so') }}">{{ __('grants.title') }}</a>
<h1 class="text-2xl font-bold">{{ __('grants.manage') }} — {{ $grant->job?->title }} / {{ $grant->company?->name }}</h1>
@if (session('status'))<p role="status">{{ session('status') }}</p>@endif
@foreach ($errors->all() as $error)<p role="alert">{{ $error }}</p>@endforeach
<section class="space-y-4 rounded border bg-white p-4"><h2 class="text-xl">{{ __('grants.revoke') }}</h2>
<p>{{ __('grants.preview') }}</p>
<table class="w-full"><thead><tr><th>{{ __('grants.code') }}</th><th>{{ __('grants.start') }}</th><th>{{ __('grants.end') }}</th></tr></thead><tbody>
@forelse ($grants as $item)<tr><td>{{ $item->id }}</td><td>{{ \App\Support\VietnamTime::format($item->starts_at) }}</td><td>{{ \App\Support\VietnamTime::format($item->ends_at) }}</td></tr>
@empty<tr><td colspan="3">{{ __('grants.empty') }}</td></tr>@endforelse
</tbody></table>
@if ($grants->isNotEmpty())
<form method="post" action="{{ route('grants.revoke', ['id' => $grant->id]) }}" class="space-y-3">@csrf
<input type="hidden" name="preview_signature" value="{{ $signature }}">
<label class="block">{{ __('grants.reason') }}<select class="block rounded border p-2" name="reason" required><option value=""></option>@foreach (\App\Services\AccessGrantLifecycle::REASONS as $reason)<option value="{{ $reason }}">{{ __('grants.reasons.'.$reason) }}</option>@endforeach</select></label>
<button class="rounded bg-red-700 px-4 py-2 text-white" type="submit">{{ __('grants.confirm_revoke') }}</button></form>
@endif</section>
<section class="space-y-4 rounded border bg-white p-4"><h2 class="text-xl">{{ __('grants.renew') }}</h2><p>{{ __('grants.renew_help') }}</p>
<form method="post" action="{{ route('grants.renew', ['id' => $grant->id]) }}" class="space-y-3">@csrf
<label class="block">{{ __('payments.method') }}<select class="block rounded border p-2" name="method" required>@foreach (\App\Models\Order::METHODS as $method)<option value="{{ $method }}">{{ __('payments.methods.'.$method) }}</option>@endforeach</select></label>
<label class="block">{{ __('grants.gift_reason') }}<textarea class="block w-full rounded border p-2" name="reason" maxlength="2000"></textarea></label>
<label class="block">{{ __('grants.gift_days') }}<input class="block rounded border p-2" type="number" name="duration_days" min="1" max="{{ $bounds['max'] }}"></label>
<button class="rounded bg-teal-700 px-4 py-2 text-white" type="submit">{{ __('grants.renew') }}</button>
</form></section></main></body></html>
