<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>{{ __('boosts.orders') }}</title>@vite('resources/css/app.css')</head>
<body class="bg-slate-50 text-slate-900"><main class="mx-auto max-w-3xl space-y-5 p-6"><a class="underline" href="{{ url('/app/don-day-tin') }}">{{ __('boosts.orders') }}</a>
<h1 class="text-2xl font-bold">{{ __('boosts.orders') }} #{{ $order->id }}</h1><p>{{ $order->company?->name }} — {{ $order->job?->title }}</p>
<h2 class="text-xl">{{ __('boosts.snapshot') }}</h2><dl>
<dt>{{ __('boosts.plan') }}</dt><dd>{{ $order->boost_plan_name }}</dd><dt>{{ __('boosts.days') }}</dt><dd>{{ $order->boost_days }}</dd>
<dt>{{ __('boosts.priority') }}</dt><dd>{{ $order->boost_priority }}</dd><dt>{{ __('orders.amount') }}</dt><dd>{{ number_format($order->amount, 0, ',', '.') }} VND</dd>
<dt>{{ __('payments.status') }}</dt><dd>{{ __('payments.states.'.$order->status) }}</dd><dt>{{ __('payments.method') }}</dt><dd>{{ __('payments.methods.'.$order->method) }}</dd>
<dt>{{ __('orders.reference') }}</dt><dd>{{ $order->payment_reference }}</dd>
@if (is_array($order->payment_instructions))@foreach (['bank','account','holder'] as $key)<dt>{{ __('orders.'.$key) }}</dt><dd>{{ $order->payment_instructions[$key] ?? '' }}</dd>@endforeach@endif
@if ($order->reason)<dt>{{ __('boosts.reason') }}</dt><dd>{{ $order->reason }}</dd>@endif</dl>
@if ($boost)<p>{{ __('boosts.paid') }}</p><p>{{ __('boosts.code') }}: {{ $boost->id }}</p><p>{{ __('boosts.starts') }}: {{ \App\Support\VietnamTime::format($boost->starts_at, 'd/m/Y H:i:s') }}</p><p>{{ __('boosts.ends') }}: {{ \App\Support\VietnamTime::format($boost->ends_at, 'd/m/Y H:i:s') }}</p>
@elseif ($order->status === 'paid')<p role="alert">{{ __('payments.rejections.paid_without_boost') }}</p>
@elseif ($order->status === 'pending' && $canConfirm)
<p>{{ __('boosts.time_help') }}</p><form method="post" action="{{ route('boost-orders.confirm', ['id' => $order->id]) }}" class="space-y-4">@csrf
@if ($order->amount > 0)<label class="block">{{ __('payments.received_amount') }}<input name="received_amount" type="number" min="1" step="1" required class="block rounded border p-2"></label>
<label class="block">{{ __('payments.transaction_reference') }}<input name="transaction_reference" maxlength="255" required class="block rounded border p-2"></label>
@else<input type="hidden" name="received_amount" value="0">@endif
<button type="submit" class="rounded bg-teal-700 px-4 py-2 text-white">{{ __('boosts.confirm') }}</button></form>
@else<p>{{ __('boosts.wait_admin') }}</p>@endif
</main></body></html>
