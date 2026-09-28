@extends('layouts.staf')
@section('judul', 'Tunggakan')
@php
  $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
  $tahap = ['terlambat_1' => ['Tahap 1 · 1 bulan', 'c-gold'], 'terlambat_2' => ['Tahap 2 · 2 bulan', 'c-warn'], 'terlambat_3' => ['Tahap 3 · ≥3 bulan', 'c-crit']];
@endphp
@section('halaman')
<div class="top"><div><h1>Tunggakan</h1><p class="muted">Peringatan terakhir per santri. Tidak ada denda. Pada tahap 3, Keuangan memutuskan setelah wali membaca pemberitahuan.</p></div>
  <div class="seg" role="group" aria-label="Tampilan">
    <a class="segbtn" href="{{ route('tunggakan.index') }}" aria-pressed="{{ $filter === 'perlu' ? 'true' : 'false' }}">Perlu tindakan</a>
    <a class="segbtn" href="{{ route('tunggakan.index', ['filter' => 'semua']) }}" aria-pressed="{{ $filter === 'semua' ? 'true' : 'false' }}">Semua</a>
  </div></div>
@error('tunggakan')<div class="alert err" role="alert">{{ $message }}</div>@enderror
@if ($menungguKeputusan)<div class="note crit" style="margin-bottom:14px"><b>{{ $menungguKeputusan }} santri</b> di tahap 3 menunggu keputusan.</div>@endif

<div class="card"><div class="tw"><table>
  <thead><tr><th>Santri</th><th>Tahap</th><th class="r">Tunggakan</th><th>Dikirim</th><th>Dibaca wali</th><th>Keputusan</th></tr></thead>
  <tbody>
  @forelse ($baris as $p)
    <tr>
      <td>{{ $p->santri->nama }}<br><span class="hint">{{ $p->bulan_tunggakan }} bulan · per {{ $p->bulan_acuan->translatedFormat('M Y') }}</span></td>
      <td><span class="chip {{ $tahap[$p->tahap->value][1] ?? '' }}">{{ $tahap[$p->tahap->value][0] ?? $p->tahap->value }}</span></td>
      <td class="r rp">{{ $rp($p->total_tunggakan) }}</td>
      <td class="num">{{ $p->dikirim_pada?->translatedFormat('d M H:i') ?? '—' }}</td>
      <td>@if ($p->dibaca_pada)<span class="chip c-good">{{ $p->dibaca_pada->translatedFormat('d M H:i') }}</span>@else<span class="chip c-warn">Belum</span>@endif</td>
      <td>
        @if ($p->tahap->value !== 'terlambat_3')
          <span class="hint">Belum perlu</span>
        @elseif ($p->keputusan)
          <span class="chip c-acc">{{ ucfirst($p->keputusan->value) }}</span>@if ($p->catatan)<br><span class="hint">{{ $p->catatan }}</span>@endif
        @else
          <form method="post" action="{{ route('tunggakan.putuskan', $p) }}" class="form-baris">@csrf
            <div class="field" style="flex:2 1 160px"><label for="c{{ $p->id }}">Catatan</label><input id="c{{ $p->id }}" name="catatan" type="text" maxlength="500" placeholder="mis. cicil 3x mulai Oktober"></div>
            <button class="btn sm" name="keputusan" value="cicilan">Cicilan</button>
            <button class="btn sm" name="keputusan" value="dispensasi">Dispensasi</button>
            <button class="btn d sm" name="keputusan" value="pemulangan" @disabled(! $p->dibaca_pada) title="{{ $p->dibaca_pada ? '' : 'Wali belum membaca pemberitahuan' }}"
              onclick="return confirm('Putuskan pemulangan {{ $p->santri->nama }}? Wali akan diberi tahu.')">Pemulangan</button>
          </form>
        @endif
      </td>
    </tr>
  @empty
    <tr><td colspan="6" class="muted">Tidak ada tunggakan yang perlu ditindaklanjuti.</td></tr>
  @endforelse
  </tbody>
</table></div></div>
@endsection
