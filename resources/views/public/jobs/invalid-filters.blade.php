<x-public-layout :title="__('public.invalid_filters')">
    <section class="site-shell section-block">
        <h1 class="filter-error-heading">{{ __('public.invalid_filters') }}</h1>
        <ul class="filter-errors">@foreach ($messages as $message)<li>{{ $message }}</li>@endforeach</ul>
        <a class="button button-primary" href="{{ route('jobs.index') }}">{{ __('public.clear_filters') }}</a>
    </section>
</x-public-layout>
