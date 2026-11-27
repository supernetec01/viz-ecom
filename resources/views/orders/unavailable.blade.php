<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><title>{{ __('orders.open_access') }}</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-slate-50 text-slate-900">
<main class="mx-auto max-w-3xl space-y-6 p-6">
    <h1 class="text-2xl font-bold">{{ __('orders.open_access') }}</h1>
    <p role="alert">{{ $message }}</p>
    <a class="underline" href="{{ url('/app/mo-ho-so') }}">{{ __('orders.back') }}</a>
</main>
</body>
</html>
