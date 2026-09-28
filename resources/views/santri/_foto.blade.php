{{-- Foto santri atau inisial. Pakai: @include('santri._foto', ['s' => $santri, 'ukuran' => 'sm|lg']) --}}
@php $inisial = collect(preg_split('/\s+/', trim($s->nama)))->filter()->take(2)->map(fn ($k) => mb_strtoupper(mb_substr($k, 0, 1)))->implode(''); @endphp
<span class="foto {{ $ukuran ?? 'sm' }}" aria-hidden="{{ $s->foto ? 'false' : 'true' }}">
  @if ($s->foto)<img src="{{ route('santri.foto', ['santri' => $s, 'v' => $s->updated_at?->timestamp]) }}" alt="Foto {{ $s->nama }}" loading="lazy">@else{{ $inisial }}@endif
</span>
