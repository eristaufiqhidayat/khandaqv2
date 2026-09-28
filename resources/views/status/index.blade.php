@extends('layouts.staf')
@section('judul', 'Status pembayaran')
@php
  $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
  $sel = ['lunas' => ['lunas', '✓', 'lunas'], 'terlambat' => ['terlambat', '!', 'terlambat'], 'sebagian' => ['sebagian', '½', 'dibayar sebagian'], 'belum' => ['belum', '', 'belum jatuh tempo'], '-' => ['kosong', '', 'belum terbit']];
  $url = fn (array $ubah) => route('laporan.status', array_merge(request()->only(['ta', 'kelas', 'status', 'tab']), $ubah));
@endphp
@section('halaman')
<div class="top"><div><h1>Status pembayaran</h1><p class="muted">Tahun ajaran {{ $ta->nama }} · SPP lunas {{ $ringkasan['lunas'] }} dari {{ $ringkasan['berbayar'] }} santri berbayar ({{ $ringkasan['persen'] }}%)</p></div></div>

<form class="row" method="get" style="margin-bottom:14px">
  <div class="seg" role="group" aria-label="Jenis">
    <a href="{{ $url(['tab' => 'spp']) }}" class="segbtn" aria-pressed="{{ $tab === 'spp' ? 'true' : 'false' }}">SPP bulanan</a>
    <a href="{{ $url(['tab' => 'dsb']) }}" class="segbtn" aria-pressed="{{ $tab === 'dsb' ? 'true' : 'false' }}">DSB & Daftar Ulang</a>
  </div>
  <input type="hidden" name="tab" value="{{ $tab }}">
  @if ($tab === 'spp')
    <select name="ta" aria-label="Tahun ajaran" style="width:auto" onchange="this.form.submit()">@foreach ($daftarTa as $t)<option value="{{ $t->id }}" @selected($t->id === $ta->id)>{{ $t->nama }}</option>@endforeach</select>
    <select name="kelas" aria-label="Kelas" style="width:auto" onchange="this.form.submit()"><option value="">Semua kelas</option>@foreach ($daftarKelas as $k)<option value="{{ $k->id }}" @selected($kelas?->id === $k->id)>{{ $k->nama }}</option>@endforeach</select>
    <select name="status" aria-label="Status" style="width:auto" onchange="this.form.submit()">
      <option value="semua">Semua santri</option><option value="menunggak" @selected($status === 'menunggak')>Menunggak</option><option value="lunas" @selected($status === 'lunas')>Lancar</option>
    </select>
    <noscript><button class="btn">Tampilkan</button></noscript>
  @endif
</form>

@if ($tab === 'spp')
  @php($menunggak = collect($spp)->where('bulan_terlambat', '>', 0))
  <div class="card">
    <div class="card-h">
      <div class="row"><span class="chip {{ $menunggak->count() ? 'c-crit' : 'c-good' }}">{{ $menunggak->count() }} santri menunggak</span>
      <span class="hint">Total tunggakan {{ $rp($menunggak->sum('sisa_terlambat')) }} · {{ count($spp) }} santri ditampilkan</span></div>
      <div class="legend">@foreach (['lunas' => 'Lunas', 'terlambat' => 'Terlambat', 'sebagian' => 'Sebagian', 'belum' => 'Belum jatuh tempo', 'kosong' => 'Belum terbit'] as $c => $l)<span><span class="bl {{ $c }} mini"></span>{{ $l }}</span>@endforeach</div>
    </div>
    <div class="tw"><table>
      <thead><tr><th>Santri</th><th>Kelas</th>
        <th style="text-transform:none;letter-spacing:0"><div class="grid12">@foreach ($bulan as $b)<span class="blh">{{ $b->translatedFormat('M') }}</span>@endforeach</div></th>
        <th class="r">Terlambat</th><th class="r">Tunggakan</th></tr></thead>
      <tbody>
      @forelse ($spp as $r)
        <tr>
          <td>{{ $r['santri']->nama }}<br><span class="hint">{{ $r['santri']->nis }}</span></td>
          <td>{{ $r['kelas'] ?? '—' }}</td>
          <td><div class="grid12">@foreach ($r['bulan'] as $tgl => $st)@php([$cls, $ikon, $arti] = $sel[$st])<span class="bl {{ $cls }}" title="{{ \Carbon\CarbonImmutable::parse($tgl)->translatedFormat('F Y') }}: {{ $arti }}">{{ $ikon }}</span>@endforeach</div></td>
          <td class="r num" style="{{ $r['bulan_terlambat'] ? 'color:var(--crit);font-weight:700' : '' }}">{{ $r['bulan_terlambat'] ?: '—' }}</td>
          <td class="r rp">{{ $r['sisa_terlambat'] ? $rp($r['sisa_terlambat']) : '—' }}</td>
        </tr>
      @empty
        <tr><td colspan="5" class="muted">Belum ada santri aktif untuk filter ini. Bila aplikasi baru dipasang, jalankan dulu Sinkronisasi data lama.</td></tr>
      @endforelse
      </tbody>
    </table></div>
  </div>
@else
  <div class="card">
    <div class="card-h"><div class="row"><span class="chip c-warn">{{ $dsbdu->count() }} tagihan belum lunas</span><span class="hint">Total sisa {{ $rp($dsbdu->sum('sisa')) }} · semua tahun ajaran</span></div></div>
    <div class="tw"><table>
      <thead><tr><th>Santri</th><th>Jenis</th><th class="r">Nominal</th><th class="r">Diskon</th><th class="r">Terbayar</th><th class="r">Sisa</th><th>Jatuh tempo</th></tr></thead>
      <tbody>
      @forelse ($dsbdu as $r)
        <tr>
          <td>{{ $r['santri']->nama }}</td><td><span class="chip c-acc">{{ $r['jenis'] }}</span></td>
          <td class="r rp">{{ $rp($r['nominal']) }}</td><td class="r rp">{{ $r['diskon'] ? $rp($r['diskon']) : '—' }}</td>
          <td class="r rp">{{ $rp($r['terbayar']) }}</td><td class="r rp" style="font-weight:700">{{ $rp($r['sisa']) }}</td>
          <td class="num" style="{{ $r['jatuh_tempo'] < now()->toDateString() ? 'color:var(--crit)' : '' }}">{{ \Carbon\CarbonImmutable::parse($r['jatuh_tempo'])->translatedFormat('d M Y') }}</td>
        </tr>
      @empty
        <tr><td colspan="7" class="muted">Tidak ada DSB atau Daftar Ulang yang belum lunas.</td></tr>
      @endforelse
      </tbody>
    </table></div>
  </div>
@endif
@endsection
