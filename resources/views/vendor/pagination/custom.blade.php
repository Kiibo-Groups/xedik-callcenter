@if ($paginator->hasPages())
<div class="pagination">
    {{-- Previous Page Link --}}
    @if ($paginator->onFirstPage())
        <span aria-disabled="true"><i class="bi bi-chevron-left" style="font-size:11px;"></i></span>
    @else
        <a href="{{ $paginator->previousPageUrl() }}" aria-label="@lang('pagination.previous')">
            <i class="bi bi-chevron-left" style="font-size:11px;"></i>
        </a>
    @endif

    {{-- Pagination Elements --}}
    @foreach ($elements as $element)
        @if (is_string($element))
            <span aria-disabled="true">{{ $element }}</span>
        @endif
        @if (is_array($element))
            @foreach ($element as $page => $url)
                @if ($page == $paginator->currentPage())
                    <span class="active"><span>{{ $page }}</span></span>
                @else
                    <a href="{{ $url }}">{{ $page }}</a>
                @endif
            @endforeach
        @endif
    @endforeach

    {{-- Next Page Link --}}
    @if ($paginator->hasMorePages())
        <a href="{{ $paginator->nextPageUrl() }}" aria-label="@lang('pagination.next')">
            <i class="bi bi-chevron-right" style="font-size:11px;"></i>
        </a>
    @else
        <span aria-disabled="true"><i class="bi bi-chevron-right" style="font-size:11px;"></i></span>
    @endif
</div>
@endif
