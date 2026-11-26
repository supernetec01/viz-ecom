<!doctype html>
<html lang="vi"><head><meta charset="utf-8"></head><body>
<p>{{ $companyName }}</p>
<p>{{ $jobTitle }}</p>
<p>{{ __('applications.mail_count', ['count' => $newCount]) }}</p>
<p><a href="{{ url('/app') }}">{{ __('applications.mail_action') }}</a></p>
</body></html>
