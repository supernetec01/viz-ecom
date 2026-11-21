<x-public-layout>
    <section class="hero">
        <div class="site-shell hero-grid">
            <div>
                <p class="eyebrow"><span class="status-dot" aria-hidden="true"></span>{{ __('public.eyebrow') }}</p>
                <h1>{{ __('public.hero_first') }}<br><span>{{ __('public.hero_second') }}</span></h1>
                <p class="hero-copy">{{ __('public.hero_copy') }}</p>
                <a class="button button-primary" href="{{ route('jobs.index') }}">{{ __('public.explore') }} <span aria-hidden="true">→</span></a>
                <p class="hero-footnote">{{ __('public.no_candidate_account') }}</p>
            </div>
            <aside class="hero-note" aria-label="{{ __('public.note_label') }}">
                <span class="note-symbol" aria-hidden="true">↗&#xfe0e;</span>
                <p class="eyebrow">{{ __('public.note_eyebrow') }}</p>
                <h2>{{ __('public.note_title') }}</h2>
                <ul>
                    @foreach (__('public.trust_points') as $point)
                        <li><span aria-hidden="true">✓</span>{{ $point }}</li>
                    @endforeach
                </ul>
                <div class="note-bottom">{{ __('public.note_bottom') }}<span aria-hidden="true">✳&#xfe0e;</span></div>
            </aside>
        </div>
    </section>
    <section id="viec-lam" class="site-shell section-block" aria-labelledby="jobs-heading">
        <p class="eyebrow">{{ __('public.opportunities') }}</p>
        <h2 id="jobs-heading">{{ __('public.jobs_heading') }}</h2>
        <div class="opportunity-banner"><p>{{ __('public.list_intro') }}</p><a class="button button-primary" href="{{ route('jobs.index') }}">{{ __('public.all_jobs') }} <span aria-hidden="true">→</span></a></div>
    </section>
    <section id="cach-ung-tuyen" class="how-section" aria-labelledby="how-heading">
        <div class="site-shell section-block">
            <p class="eyebrow">{{ __('public.how_eyebrow') }}</p><h2 id="how-heading">{{ __('public.how_heading') }}</h2>
            <ol class="steps-grid">
                @foreach (__('public.steps') as $step)
                    <li><span class="step-number">0{{ $loop->iteration }}</span><h3>{{ $step['title'] }}</h3><p>{{ $step['copy'] }}</p></li>
                @endforeach
            </ol>
        </div>
    </section>
</x-public-layout>
