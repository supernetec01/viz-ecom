<x-public-layout :title="$job->title" :noindex="! $accepting">
    <section class="detail-intro">
        <div class="site-shell">
            <a class="detail-back" href="{{ route('jobs.index') }}">← {{ __('public.back_to_jobs') }}</a>
            @if ($state !== 'closed')<p class="eyebrow">{{ $job->category->name }}</p>@endif
            <h1>{{ $job->title }}</h1>
            <p class="detail-company">@include('public.jobs.company-name', ['company' => $job->company])</p>
            <p class="detail-address"><strong>{{ __('public.address') }}:</strong> {{ $job->workplaceAddress() ?? $job->location->name.' — '.__('public.address_incomplete') }}</p>
        </div>
    </section>
    <div class="site-shell detail-body">
        @if (! $accepting)
            <section class="job-status-notice" aria-label="{{ __('public.application_status') }}"><h2>{{ __('public.detail_states.'.$state) }}</h2><p>{{ __('public.not_accepting') }}</p></section>
        @endif
        @if ($state !== 'closed')
            <div class="detail-grid">
                <div class="detail-content">
                    @foreach (['description', 'requirements', 'benefits'] as $field)
                        <section class="detail-section"><h2>{{ __('ui.jobs.'.$field) }}</h2><div class="job-rich-text">{!! \App\Support\JobHtml::clean((string)$job->{$field}) !!}</div></section>
                    @endforeach
                </div>
                <aside class="detail-summary" aria-label="{{ __('public.job_summary') }}">
                    <h2>{{ __('public.job_summary') }}</h2>
                    <p class="job-salary">{{ \App\Support\JobPresentation::salary($job) }}</p>
                    <dl class="job-facts">
                        <div><dt>{{ __('ui.jobs.employment_type') }}</dt><dd>{{ __('ui.jobs.employment_types.'.$job->employment_type->value) }}</dd></div>
                        <div><dt>{{ __('public.experience') }}</dt><dd>{{ $job->experience_years ? __('public.experience_years', ['years' => $job->experience_years]) : __('public.no_experience') }}</dd></div>
                        <div><dt>{{ __('ui.jobs.vacancies') }}</dt><dd>{{ $job->vacancies }}</dd></div>
                        <div><dt>{{ __('ui.jobs.published_at') }}</dt><dd>{{ \App\Support\VietnamTime::format($job->published_at, 'd/m/Y') }}</dd></div>
                        <div><dt>{{ __('ui.jobs.expires_at') }}</dt><dd>{{ $job->expires_at ? \App\Support\VietnamTime::format($job->expires_at, 'd/m/Y') : '—' }}</dd></div>
                    </dl>
                    @if ($accepting)<section class="application-area" aria-label="{{ __('public.application_status') }}"><h3>{{ __('public.detail_states.published') }}</h3><p>{{ __('public.application_not_open') }}</p></section>@endif
                </aside>
            </div>
        @endif
        <section class="similar-section" aria-labelledby="similar-heading">
            <h2 id="similar-heading">{{ __('public.similar_jobs') }}</h2>
            @if ($similar->isEmpty())<p>{{ __('public.no_similar_jobs') }}</p>@else
                <ul class="similar-grid">
                    @foreach ($similar as $suggestion)
                        <li class="similar-card" data-similar-job-id="{{ $suggestion->id }}"><h3><a class="job-title-link" href="{{ route('jobs.show', ['slug' => $suggestion->slug, 'id' => $suggestion->id]) }}">{{ $suggestion->title }}</a></h3>
                            <p>@include('public.jobs.company-name', ['company' => $suggestion->company])</p>
                            <p>{{ $suggestion->workplaceAddress() ?? $suggestion->location->name.' — '.__('public.address_incomplete') }}</p>
                        </li>
                    @endforeach
                </ul>
            @endif
            <a class="button button-outline" href="{{ route('jobs.index') }}">{{ __('public.all_jobs') }}</a>
        </section>
    </div>
</x-public-layout>
