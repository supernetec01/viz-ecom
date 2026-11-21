<x-public-layout :title="$company->name" :description="\App\Support\PublicSeo::text($company->name.' · '.$company->address.' · '.$company->introduction)" :canonical="\App\Support\PublicSeo::url('companies.show', ['id' => $company->id] + ($jobs->currentPage() > 1 ? ['page' => $jobs->currentPage()] : []))">
    <section class="listing-intro company-intro"><div class="site-shell">
        <p class="eyebrow">{{ __('public.company_profile') }}</p>
        <h1>{{ $company->name }}</h1>
        <p>{{ __('ui.companies.types.'.$company->type->value) }}</p>
        <p>{{ $company->address }}</p>
    </div></section>
    <section class="site-shell section-block company-profile" aria-label="{{ __('public.company_profile') }}">
        @foreach (['introduction', 'industry', 'products'] as $field)
            @if ($company->{$field})<article><h2>{{ __('public.company_'.$field) }}</h2><p class="company-copy">{{ $company->{$field} }}</p></article>@endif
        @endforeach
        @if ($channels->isNotEmpty())
            <article><h2>{{ __('public.company_channels') }}</h2><p>{{ __('public.external_link_notice') }}</p>
                <ul class="company-channels">@foreach ($channels as $channel)
                    <li><a href="{{ $channel->url }}" target="_blank" rel="noopener noreferrer">{{ $channel->display_name }} <span aria-hidden="true">↗</span></a></li>
                @endforeach</ul>
            </article>
        @endif
    </section>
    <section class="site-shell section-block" aria-labelledby="company-jobs">
        <div class="list-heading"><h2 id="company-jobs">{{ __('public.list_heading') }}</h2><p>{{ __('public.list_total', ['count' => $jobs->total()]) }}</p></div>
        @if ($jobs->isEmpty())<p>{{ __('public.no_jobs_title') }}</p>@else
            <ol class="job-grid">@foreach ($jobs as $job)<li><article class="job-card">
                <h3><a class="job-title-link" href="{{ route('jobs.show', ['slug' => $job->slug, 'id' => $job->id]) }}">{{ $job->title }}</a></h3>
                <p class="job-salary">{{ \App\Support\JobPresentation::salary($job) }}</p>
                <p>{{ $job->workplaceAddress() ?? $job->location->name.' — '.__('public.address_incomplete') }}</p>
            </article></li>@endforeach</ol>
        @endif
        {{ $jobs->onEachSide(1)->links('public.jobs.pagination') }}
    </section>
</x-public-layout>
