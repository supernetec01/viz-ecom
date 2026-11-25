<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, follow">
    <title>{{ __('applications.upload_too_large_title') }}</title>
    @vite('resources/css/app.css')
</head>
<body class="public-site">
    <main class="site-shell detail-section">
        <h1>{{ __('applications.upload_too_large_title') }}</h1>
        <p role="alert">{{ __('applications.upload_too_large') }}</p>
        <a class="button button-outline" href="{{ route('jobs.index') }}">{{ __('public.back_to_jobs') }}</a>
    </main>
</body>
</html>
