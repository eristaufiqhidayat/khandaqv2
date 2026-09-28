@extends('layouts.staf')
@section('judul', 'Ringkasan keuangan')
@php($rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.'))
@section('halaman')
<div class="top"><div><h1>Ringkasan keuangan</h1><p class="muted">Tahun ajaran {{ $ta->nama }} · per {{ now()->translatedFormat('d F Y') }}</p></div></div>
<div class="grid g4" style="margin-bottom:16px">
  <div class="card kpi"><div class="eyebrow">Saldo titipan wali</div><div class="v rp">{{ $rp($saldoTitipan) }}</div><div class="s">{{ $santriAktif }} santri aktif</div></div>
  <div class="card kpi"><div class="eyebrow">SPP lunas</div><div class="v">{{ $spp['lunas'] }} / {{ $spp['berbayar'] }}</div><div class="s">{{ $spp['persen'] }}% santri berbayar</div></div>
  <div class="card kpi"><div class="eyebrow">Tunggakan SPP</div><div class="v rp" style="color:var(--crit)">{{ $rp($spp['total_tunggakan']) }}</div><div class="s">{{ $spp['menunggak'] }} santri · {{ $tahap3 }} menunggu keputusan tahap 3</div></div>
  <div class="card kpi"><div class="eyebrow">Setoran menunggu verifikasi</div><div class="v">{{ $menungguVerifikasi }}</div><div class="s">laporan transfer wali</div></div>
</div>
<div class="grid g2">
  <div class="card">
    <div class="card-h"><h2>Arus kas {{ now()->translatedFormat('F Y') }}</h2></div>
    @if ($bulanIni)
    <div class="tw"><table><tbody>
      <tr><td>Setoran transfer</td><td class="r rp">{{ $rp($bulanIni['kas_bank_masuk']['transfer']) }}</td></tr>
      <tr><td>Setoran tunai</td><td class="r rp">{{ $rp($bulanIni['kas_bank_masuk']['tunai']) }}</td></tr>
      <tr><td>Penarikan uang saku</td><td class="r rp">−{{ $rp($bulanIni['kas_keluar_penarikan']) }}</td></tr>
      @foreach ($bulanIni['pendapatan_per_dana'] as $dana => $n)<tr><td>Dibayarkan ke dana {{ $dana }}</td><td class="r rp">{{ $rp($n) }}</td></tr>@endforeach
    </tbody></table></div>
    @else <p class="muted">Bulan ini di luar tahun ajaran aktif.</p> @endif
  </div>
  <div class="card">
    <div class="card-h"><h2>DSB & Daftar Ulang belum lunas</h2><span class="chip c-warn">{{ $dsbdu->count() }}</span></div>
    <div class="tw"><table><thead><tr><th>Santri</th><th>Jenis</th><th class="r">Sisa</th></tr></thead><tbody>
      @forelse ($dsbdu->take(10) as $r)<tr><td>{{ $r['santri']->nama }}</td><td>{{ $r['jenis'] }}</td><td class="r rp">{{ $rp($r['sisa']) }}</td></tr>
      @empty<tr><td colspan="3" class="muted">Tidak ada.</td></tr>@endforelse
    </tbody></table></div>
  </div>
</div>
@endsection
