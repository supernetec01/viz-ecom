<x-public-layout :title="__('applications.thanks_title')" :description="__('applications.received')" :noindex="true">
    <section class="listing-intro">
      <div class="site-shell">
        <h1>{{ __('applications.thanks_title') }}</h1>
        <p role="status">{{ __('applications.received') }}</p>
      </div>
    </section>
    <section class="site-shell section-block">
        <p>{{ __('applications.thanks_next') }}</p>
        <a class="button button-primary" href="{{ route('jobs.index') }}">{{ __('applications.browse_jobs') }}</a>
    </section>
</x-public-layout>
