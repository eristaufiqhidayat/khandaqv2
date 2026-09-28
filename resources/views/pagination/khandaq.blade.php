{{-- Paginasi Khandaq: tanpa Tailwind, memakai kelas .btn dari khandaq.css. --}}
@if ($paginator->hasPages())
<nav class="paginasi" role="navigation" aria-label="Halaman">
  @if ($paginator->onFirstPage())
    <span class="btn sm" aria-disabled="true">‹ Sebelumnya</span>
  @else
    <a class="btn sm" href="{{ $paginator->previousPageUrl() }}" rel="prev">‹ Sebelumnya</a>
  @endif

  @isset($elements)
    <span class="paginasi-nomor">
    @foreach ($elements as $element)
      @if (is_string($element))
        <span class="hint">{{ $element }}</span>
      @endif
      @if (is_array($element))
        @foreach ($element as $page => $url)
          @if ($page == $paginator->currentPage())
            <span class="btn sm p" aria-current="page">{{ $page }}</span>
          @else
            <a class="btn sm" href="{{ $url }}">{{ $page }}</a>
          @endif
        @endforeach
      @endif
    @endforeach
    </span>
  @endisset

  @if ($paginator->hasMorePages())
    <a class="btn sm" href="{{ $paginator->nextPageUrl() }}" rel="next">Berikutnya ›</a>
  @else
    <span class="btn sm" aria-disabled="true">Berikutnya ›</span>
  @endif

  @if (method_exists($paginator, 'total'))
    <span class="hint">{{ $paginator->firstItem() }}–{{ $paginator->lastItem() }} dari {{ number_format($paginator->total(), 0, ',', '.') }}</span>
  @endif
</nav>
@endif
