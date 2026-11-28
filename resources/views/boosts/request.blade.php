<!doctype html><html lang="vi"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="robots" content="noindex,nofollow"><title>{{ __('boosts.create_order') }}</title>@vite('resources/css/app.css')</head>
<body class="bg-slate-50 text-slate-900"><main class="mx-auto max-w-3xl space-y-5 p-6">
<a href="{{ url('/app/day-tin') }}" class="underline">{{ __('boosts.back') }}</a><h1 class="text-2xl font-bold">{{ __('boosts.create_order') }}</h1>
<p>{{ $job->title }} — {{ $job->company?->name }}</p><p>{{ __('boosts.help') }}</p>
@if ($plans->isEmpty())<p>{{ __('boosts.no_plans') }}</p>@else
<form action="{{ route('boost-orders.store', ['id' => $job->id]) }}" method="post" class="space-y-4">@csrf
<label class="block">{{ __('boosts.plan') }}<select name="plan_id" required class="block w-full rounded border p-2">@foreach ($plans as $plan)<option value="{{ $plan->id }}">{{ $plan->name }} — {{ $plan->duration_days }} {{ __('boosts.day_unit') }} — {{ number_format($plan->price, 0, ',', '.') }} VND — {{ __('boosts.priority') }} {{ $plan->priority }}</option>@endforeach</select></label>
<label class="block">{{ __('payments.method') }}<select name="method" required class="block rounded border p-2">@foreach (\App\Models\Order::METHODS as $method)<option value="{{ $method }}">{{ __('payments.methods.'.$method) }}</option>@endforeach</select></label>
<label class="block">{{ __('boosts.reason') }}<textarea name="reason" maxlength="2000" class="block w-full rounded border p-2"></textarea></label>
<button class="rounded bg-teal-700 px-4 py-2 text-white" type="submit">{{ __('boosts.create_order') }}</button></form>@endif
</main></body></html>
