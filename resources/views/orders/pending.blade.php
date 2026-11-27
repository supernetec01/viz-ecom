<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><title>{{ __('orders.pending_title') }}</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-slate-50 text-slate-900">
<main class="mx-auto max-w-3xl space-y-6 p-6">
    <a class="underline" href="{{ url('/app/mo-ho-so') }}">{{ __('orders.open_access') }}</a>
    <h1 class="text-2xl font-bold">{{ __('orders.pending_title') }}</h1>
    <p>{{ __('orders.pending_notice') }}</p>
    <dl class="space-y-3 break-words rounded border bg-white p-4">
        <dt>{{ __('orders.company') }}</dt><dd>{{ $order->company?->name }}</dd>
        <dt>{{ __('orders.job') }}</dt><dd>{{ $order->job?->title }}</dd>
        <dt>{{ __('orders.order_code') }}</dt><dd>{{ $order->id }}</dd>
        <dt>{{ __('orders.amount') }}</dt><dd>{{ number_format($order->amount, 0, ',', '.') }} VND</dd>
        <dt>{{ __('orders.bank') }}</dt><dd>{{ $order->payment_instructions['bank'] }}</dd>
        <dt>{{ __('orders.account') }}</dt><dd>{{ $order->payment_instructions['account'] }}</dd>
        <dt>{{ __('orders.holder') }}</dt><dd>{{ $order->payment_instructions['holder'] }}</dd>
        <dt>{{ __('orders.reference') }}</dt><dd>{{ $order->payment_reference }}</dd>
    </dl>
    <p>{{ __('orders.scope_notice') }}</p>
    <p>{{ __('orders.manual_notice') }}</p>
</main>
</body>
</html>
