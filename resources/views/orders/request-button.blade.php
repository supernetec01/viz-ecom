<div class="px-3 py-2">
    @if ($getRecord()->access_active)
        <span>{{ __('orders.already_unlocked') }}</span>
    @else
        <form method="post" action="{{ route('access-orders.store', ['job' => $getRecord()->id]) }}">
            @csrf
            <button type="submit" class="font-medium underline">{{ __('orders.request_access') }}</button>
        </form>
    @endif
</div>
