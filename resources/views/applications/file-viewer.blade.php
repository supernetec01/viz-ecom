<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><title>{{ __('viewer.title') }}</title>
    @vite(['resources/css/app.css', 'resources/js/private-file-viewer.js'])
</head>
<body class="bg-slate-50 text-slate-900">
<main class="viewer-shell">
    <a href="{{ route('applications.detail', ['id' => $id]) }}">{{ __('viewer.back') }}</a>
    <h1>{{ __('viewer.title') }} — {{ $state['label'] }}</h1>
    <p>{{ $state['message'] }}</p>
    @if ($state['can_download'])
        <a class="viewer-button" href="{{ route('applications.file.download', ['id' => $id, 'file' => $state['id']]) }}">{{ __('viewer.download') }}</a>
    @endif
    @if ($url)
        <section id="file-viewer" data-url="{{ $url }}" data-pdf="{{ in_array($state['mime'], ['application/pdf', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document'], true) ? '1' : '0' }}"
            data-error="{{ __('viewer.failed') }}" data-loading="{{ __('viewer.loading') }}" data-page-label="{{ __('viewer.page') }}" data-assets="{{ asset('build/pdfjs') }}/">
            <div class="viewer-controls" hidden>
                <button type="button" data-previous>{{ __('viewer.previous') }}</button>
                <output aria-live="polite" data-page></output>
                <button type="button" data-next>{{ __('viewer.next') }}</button>
                <label>{{ __('viewer.zoom') }} <select data-zoom><option value="1">100%</option><option value="1.5">150%</option><option value="2">200%</option></select></label>
            </div>
            <p role="status" data-status>{{ __('viewer.loading') }}</p>
            <p data-error-message hidden>{{ __('viewer.failed') }} <a href="{{ route('applications.file.viewer', ['id' => $id, 'file' => $state['id']]) }}">{{ __('viewer.reload') }}</a></p>
            <div class="viewer-surface"><canvas aria-label="{{ __('viewer.title') }}" hidden></canvas><img alt="{{ __('viewer.title') }}" hidden></div>
        </section>
        <noscript>{{ __('viewer.no_script') }}</noscript>
    @endif
</main>
</body>
</html>
