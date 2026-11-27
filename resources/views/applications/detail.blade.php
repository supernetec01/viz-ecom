<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow"><title>{{ __('applications.summary_singular') }}</title>
    @vite('resources/css/app.css')
</head>
<body class="bg-slate-50 text-slate-900">
<main class="mx-auto max-w-3xl space-y-6 p-6">
    <a href="{{ url('/app') }}">{{ __('applications.mail_action') }}</a>
    <h1 class="text-2xl font-bold">{{ $record->full_name }}</h1>
    <dl class="space-y-3">
        <dt>{{ __('applications.fields.phone') }}</dt><dd>{{ $record->phone_normalized }}</dd>
        <dt>{{ __('applications.fields.email') }}</dt><dd>{{ $record->email }}</dd>
        <dt>{{ __('applications.summary_submitted') }}</dt><dd>{{ \App\Support\VietnamTime::format($record->submitted_at) }}</dd>
    </dl>
    <section aria-label="{{ __('viewer.files') }}" class="space-y-4">
        @foreach ($files as $file)
            <article class="rounded border bg-white p-4">
                <h2 class="font-bold">{{ $file['label'] }}</h2>
                <p>{{ $file['message'] }}</p>
                @if ($file['can_view'])
                    <a class="underline" href="{{ route('applications.file.viewer', ['id' => $record->id, 'file' => $file['id']]) }}">{{ __('viewer.open') }}</a>
                @endif
                @if ($file['can_download'])
                    <a class="underline" href="{{ route('applications.file.download', ['id' => $record->id, 'file' => $file['id']]) }}">{{ __('viewer.download') }}</a>
                @endif
            </article>
        @endforeach
    </section>
    @if ($record->portfolio_url && filter_var($record->portfolio_url, FILTER_VALIDATE_URL) && in_array(strtolower(parse_url($record->portfolio_url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true))
        <p><a href="{{ $record->portfolio_url }}" target="_blank" rel="noopener noreferrer">{{ __('applications.fields.portfolio_url') }}</a></p>
        <p>{{ __('applications.external_warning') }}</p>
    @endif
    <form method="post" action="{{ route('applications.detail.update', ['id' => $record->id]) }}" class="space-y-4">
        @csrf
        <input type="hidden" name="edit_version" value="{{ $record->edit_version }}">
        <label class="block">{{ __('applications.detail_status') }}
            <select name="status" class="block border p-2">
                @foreach (['new', 'viewed', 'interview', 'hired', 'rejected'] as $status)
                    <option value="{{ $status }}" @selected($record->status === $status)>{{ __('applications.statuses.'.$status) }}</option>
                @endforeach
            </select>
        </label>
        <label class="block">{{ __('applications.detail_notes') }}
            <textarea name="internal_notes" maxlength="2000" rows="6" class="block w-full border p-2">{{ $record->internal_notes }}</textarea>
        </label>
        @if ($record->note_updated_at)
            <p>{{ __('applications.note_editor', ['name' => $editor ?? __('applications.unknown_editor'), 'role' => __('applications.roles.'.$record->note_updated_role), 'time' => \App\Support\VietnamTime::format($record->note_updated_at)]) }}</p>
        @endif
        <button type="submit" class="rounded bg-teal-700 px-4 py-2 text-white">{{ __('applications.detail_save') }}</button>
    </form>
</main>
</body>
</html>
