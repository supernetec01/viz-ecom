<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><title>{{ __('payments.title') }}</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-slate-50 text-slate-900">
<main class="mx-auto max-w-3xl space-y-6 p-6">
    <a class="underline" href="{{ url('/app/xac-nhan-thanh-toan') }}">{{ __('payments.title') }}</a>
    <h1 class="text-2xl font-bold">{{ __('payments.title') }} #{{ $order->id }}</h1>
    @if ($errors)
        <div role="alert" class="rounded border p-4">@foreach ($errors as $messages) @foreach ($messages as $message) <p>{{ $message }}</p> @endforeach @endforeach</div>
    @endif
    <dl class="space-y-2 rounded border bg-white p-4">
        <dt>{{ __('orders.company') }}</dt><dd>{{ $order->company?->name ?? __('payments.unavailable_scope') }}</dd>
        <dt>{{ __('orders.job') }}</dt><dd>{{ $order->job?->title ?? __('payments.unavailable_scope') }}</dd>
        <dt>{{ __('orders.amount') }}</dt><dd>{{ number_format($order->amount, 0, ',', '.') }} VND</dd>
        <dt>{{ __('payments.status') }}</dt><dd>{{ __('payments.states.'.$order->status) }}</dd>
        <dt>{{ __('payments.method') }}</dt><dd>{{ __('payments.methods.'.$order->method) }}</dd>
        @if (is_array($order->payment_instructions))
            @foreach (['bank', 'account', 'holder'] as $field)
                <dt>{{ __('orders.'.$field) }}</dt><dd>{{ $order->payment_instructions[$field] ?? '' }}</dd>
            @endforeach
        @endif
        <dt>{{ __('orders.reference') }}</dt><dd>{{ $order->payment_reference }}</dd>
    </dl>
    @if ($order->status === 'paid')
        @if ($order->accessGrant)
            <p>{{ __('payments.already_confirmed') }}</p>
            <p>{{ __('payments.confirmed_at') }}: {{ \App\Support\VietnamTime::format($order->confirmed_at, 'd/m/Y H:i:s') }}</p>
            @if ($order->transaction_reference)<p>{{ __('payments.transaction_reference') }}: {{ $order->transaction_reference }}</p>@endif
            <p>{{ __('payments.grant_code') }}: {{ $order->accessGrant->id }}</p>
            <p>{{ __('payments.starts_at') }}: {{ \App\Support\VietnamTime::format($order->accessGrant->starts_at, 'd/m/Y H:i:s') }}</p>
            <p>{{ __('payments.ends_at') }}: {{ \App\Support\VietnamTime::format($order->accessGrant->ends_at, 'd/m/Y H:i:s') }}</p>
        @else
            <p role="alert">{{ __('payments.rejections.paid_without_grant') }}</p>
        @endif
    @elseif ($order->status === 'pending')
        <form method="post" action="{{ route('payments.store', ['id' => $order->id]) }}" class="space-y-4 rounded border bg-white p-4">
            @csrf
            <input type="hidden" name="default_ends_at" value="{{ $default }}">
            <input type="hidden" name="default_signature" value="{{ $signature }}">
            <p>{{ __('payments.starts_server') }}</p>
            <label class="block">{{ __('payments.ends_at') }}
                <input class="block w-full rounded border p-2" type="datetime-local" step="1" name="ends_at" value="{{ $values['ends_at'] ?? $default }}" required>
            </label>
            <p>{{ __('payments.end_hint', $bounds) }}</p>
            @if ($order->duration_days === null)<p>{{ __('payments.legacy_end') }}</p>@endif
            @if ($order->method === 'bank_transfer')
                <label class="block">{{ __('payments.received_amount') }}
                    <input class="block w-full rounded border p-2" type="number" name="received_amount" min="0" step="1" value="{{ $values['received_amount'] ?? '' }}" required>
                </label>
                <label class="block">{{ __('payments.transaction_reference') }}
                    <input class="block w-full rounded border p-2" type="text" name="transaction_reference" maxlength="255" value="{{ $values['transaction_reference'] ?? '' }}" required>
                </label>
            @else
                <input type="hidden" name="received_amount" value="0">
                <p>{{ __('payments.zero_reason') }}: {{ $order->reason }}</p>
            @endif
            <button class="rounded bg-teal-700 px-4 py-2 text-white" type="submit">{{ __('payments.confirm_button') }}</button>
        </form>
    @endif
</main>
</body>
</html>
