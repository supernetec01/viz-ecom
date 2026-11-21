@props(['title' => null, 'noindex' => false, 'description' => null, 'structuredData' => null, 'canonical' => null])
@php
    $pageTitle = $title ? $title.' — '.__('ui.app_name') : __('public.home_title').' — '.__('ui.app_name');
    $pageDescription = $description ?? __('public.hero_copy');
    $canonical ??= \App\Support\PublicSeo::url(request()->route()->getName(), request()->route()->parameters());
    $image = rtrim(config('app.url'), '/').'/images/social-card.png';
@endphp
<!DOCTYPE html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $pageTitle }}</title>
    <meta name="description" content="{{ $pageDescription }}">
    <link rel="canonical" href="{{ $canonical }}">
    <meta property="og:type" content="website">
    <meta property="og:locale" content="vi_VN">
    <meta property="og:site_name" content="{{ __('ui.app_name') }}">
    <meta property="og:title" content="{{ $pageTitle }}">
    <meta property="og:description" content="{{ $pageDescription }}">
    <meta property="og:url" content="{{ $canonical }}">
    <meta property="og:image" content="{{ $image }}">
    <meta property="og:image:type" content="image/png">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:image:alt" content="{{ __('public.home_title') }} — {{ __('ui.app_name') }}">
    @if ($noindex)<meta name="robots" content="noindex, follow">@endif
    @if ($structuredData)
        <script type="application/ld+json" nonce="{{ \Illuminate\Support\Facades\Vite::cspNonce() }}">{!! json_encode($structuredData, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
    @endif
    @vite('resources/css/app.css')
</head>
<body class="public-site">
    <a class="skip-link" href="#noi-dung">{{ __('public.skip') }}</a>
    <header class="site-header">
        <nav class="site-shell header-inner" aria-label="{{ __('public.main_nav') }}">
            <a class="brand" href="{{ route('home') }}" aria-label="{{ __('public.home_link') }}">
                <span class="brand-mark" aria-hidden="true">v.</span><span>viz<span class="brand-light"> ecom</span></span>
            </a>
            <div class="site-nav">
                <a href="{{ route('jobs.index') }}" @if(request()->routeIs('jobs.index')) aria-current="page" @endif>{{ __('public.jobs_nav') }}</a>
                <a href="{{ route('home') }}#cach-ung-tuyen">{{ __('public.how_nav') }}</a>
            </div>
            <a class="button button-outline employer-link" href="{{ route('login') }}">{{ __('public.employer') }} <span aria-hidden="true">↗&#xfe0e;</span></a>
        </nav>
    </header>
    <main id="noi-dung" tabindex="-1">{{ $slot }}</main>
    <footer class="site-footer">
        <div class="site-shell footer-inner">
            <div><a class="brand footer-brand" href="{{ route('home') }}">{{ __('ui.app_name') }}</a><p>{{ __('public.footer_line') }}</p></div>
            <nav aria-label="{{ __('public.footer_nav') }}">
                <a href="{{ route('jobs.index') }}">{{ __('public.jobs_nav') }}</a>
                <a href="{{ route('login') }}">{{ __('public.work_login') }}</a>
            </nav>
        </div>
        <div class="site-shell footer-bottom">© {{ now()->year }} {{ __('ui.app_name') }}<span>{{ __('public.no_candidate_account') }}</span></div>
    </footer>
</body>
</html>
