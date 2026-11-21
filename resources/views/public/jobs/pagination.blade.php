@if ($paginator->hasPages() && ! $paginator->isEmpty())
    <nav class="job-pagination" aria-label="{{ __('public.pagination') }}">
        @if ($paginator->onFirstPage())
            <span class="page-button disabled" aria-disabled="true">{{ __('public.previous') }}</span>
        @else
            <a class="page-button" href="{{ $paginator->previousPageUrl() }}" rel="prev">{{ __('public.previous') }}</a>
        @endif
        <p>{{ __('public.page_count', ['current' => $paginator->currentPage(), 'last' => $paginator->lastPage()]) }}</p>
        @if ($paginator->hasMorePages())
            <a class="page-button" href="{{ $paginator->nextPageUrl() }}" rel="next">{{ __('public.next') }}</a>
        @else
            <span class="page-button disabled" aria-disabled="true">{{ __('public.next') }}</span>
        @endif
    </nav>
@endif
