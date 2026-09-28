@extends('layouts.wali')
@section('judul', 'Kalender')
@section('judul-bar', 'Kalender')
@section('kembali', route('wali.beranda'))
@section('wali')
<p class="wb-sapa">Kalender akademik <b>{{ $ta->nama }}</b></p>
<section class="card wb-kartu">
  <h2 style="margin-bottom:4px">Akan datang</h2>
  @forelse ($akanDatang as $bulan => $daftar)
    <div class="eyebrow" style="margin-top:10px">{{ \Carbon\CarbonImmutable::parse($bulan.'-01')->translatedFormat('F Y') }}</div>
    @foreach ($daftar as $k)
      <div class="wb-keg"><div class="wb-tgl"><b>{{ $k->tanggal_mulai->format('d') }}</b><span>{{ $k->tanggal_mulai->translatedFormat('M') }}</span></div>
        <div><b>{{ $k->kegiatan }}</b>@if ($k->tanggal_mulai->lte($hariIni)) <span class="chip c-good">berlangsung</span>@endif<br><span class="hint">{{ $k->rentang() }}</span>
          @if ($k->keterangan)<br><span class="hint">{{ $k->keterangan }}</span>@endif</div></div>
    @endforeach
  @empty
    <p class="hint">Belum ada kegiatan yang dijadwalkan.</p>
  @endforelse
</section>
@if ($lewat->isNotEmpty())
<details class="card wb-kartu"><summary><b>Sudah lewat ({{ $lewat->count() }})</b></summary>
  @foreach ($lewat as $k)
    <div class="wb-keg lewat"><div class="wb-tgl"><b>{{ $k->tanggal_mulai->format('d') }}</b><span>{{ $k->tanggal_mulai->translatedFormat('M') }}</span></div>
      <div><b>{{ $k->kegiatan }}</b><br><span class="hint">{{ $k->rentang() }}</span></div></div>
  @endforeach
</details>
@endif
@endsection
