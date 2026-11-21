<x-public-layout :title="__('public.list_title')">
    <section class="listing-intro">
        <div class="site-shell">
            <p class="eyebrow">{{ __('public.opportunities') }}</p>
            <h1>{{ __('public.list_title') }}</h1>
            <p>{{ __('public.list_intro') }}</p>
        </div>
    </section>
    <section class="site-shell section-block job-list-section" aria-labelledby="list-heading">
        @include('public.jobs.filters')
        <div class="list-heading"><h2 id="list-heading">{{ __('public.list_heading') }}</h2><p>{{ __('public.list_total', ['count' => number_format($jobs->total(), 0, ',', '.')]) }}</p></div>
        @if ($jobs->isEmpty())
            <div class="empty-state"><span class="empty-symbol" aria-hidden="true">↗&#xfe0e;</span>
                <h3>{{ __($jobs->total() ? 'public.page_empty_title' : 'public.no_jobs_title') }}</h3>
                <p>{{ __('public.no_jobs_copy') }}</p>
                @if ($jobs->total())<a class="button button-outline" href="{{ route('jobs.index', $filters) }}">{{ __('public.first_page') }}</a>@endif
            </div>
        @else
            <ol class="job-grid" aria-label="{{ __('public.list_heading') }}">
                @foreach ($jobs as $job)
                    <li>
                        <article class="job-card" aria-labelledby="job-{{ $job->id }}" data-job-id="{{ $job->id }}">
                            <div class="job-card-top"><span class="category-tag">{{ $job->category->name }}</span><span class="job-date">{{ __('public.published_on', ['date' => $job->published_at->setTimezone(config('app.display_timezone'))->format('d/m/Y')]) }}</span></div>
                            <h3 id="job-{{ $job->id }}">{{ $job->title }}</h3>
                            <p class="job-company">{{ $job->company->name }}</p>
                            <p class="job-company-type">{{ __('ui.companies.types.'.$job->company->type->value) }}</p>
                            <p class="job-salary">{{ \App\Support\JobPresentation::salary($job) }}</p>
                            <dl class="job-facts">
                                <div><dt>{{ __('public.address') }}</dt><dd>{{ $job->workplaceAddress() ?? $job->location->name.' — '.__('public.address_incomplete') }}</dd></div>
                                <div><dt>{{ __('ui.jobs.employment_type') }}</dt><dd>{{ __('ui.jobs.employment_types.'.$job->employment_type->value) }}</dd></div>
                                <div><dt>{{ __('public.experience') }}</dt><dd>{{ $job->experience_years ? __('public.experience_years', ['years' => $job->experience_years]) : __('public.no_experience') }}</dd></div>
                            </dl>
                            <p class="job-expiry">{{ __('public.expires_on', ['date' => $job->expires_at->setTimezone(config('app.display_timezone'))->format('d/m/Y')]) }}</p>
                        </article>
                    </li>
                @endforeach
            </ol>
        @endif
        {{ $jobs->onEachSide(1)->links('public.jobs.pagination') }}
    </section>
</x-public-layout>
