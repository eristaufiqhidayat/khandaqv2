@extends('layouts.staf')
@section('judul', 'Gaji pegawai')
@php($rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.'))
@section('halaman')
<div class="top"><div><h1>Gaji pegawai</h1><p class="muted">Susun gaji bulanan lalu unduh berkas <b>.txt</b> untuk diunggah di BSINet &rsaquo; Payroll.</p></div>
  @include('gaji._tab', ['aktif' => 'gaji'])</div>
@error('gaji')<div class="alert err" role="alert">{{ $message }}</div>@enderror

<div class="grid" style="grid-template-columns:minmax(0,1fr) 320px;align-items:start">
  <div class="card"><div class="tw"><table><thead><tr><th>Penggajian</th><th>Tanggal transfer</th><th class="r">Penerima</th><th class="r">Total</th><th>Status</th></tr></thead><tbody>
  @forelse ($daftar as $p)
    <tr><td><a href="{{ route('gaji.show', $p) }}">{{ $p->judul }}</a><br><span class="hint">{{ $p->pesan }}</span></td>
      <td class="num">{{ $p->tanggal_transfer->translatedFormat('d M Y') }}</td>
      <td class="r num">{{ $p->rincian_count }}</td><td class="r rp">{{ $rp($p->rincian_sum_nominal) }}</td>
      <td>@if ($p->final())<span class="chip c-good">Terkirim</span>@else<span class="chip c-warn">Draf</span>@endif</td></tr>
  @empty
    <tr><td colspan="5" class="muted">Belum ada penggajian. @if (! $jumlahPegawai)Mulai dari <a href="{{ route('gaji.pegawai') }}">Data pegawai</a>: unggah berkas payroll BSI terakhir untuk mengisi daftar guru sekaligus.@endif</td></tr>
  @endforelse
  </tbody></table></div></div>

  <form class="card stack" method="post" action="{{ route('gaji.store') }}">@csrf<h2>Buat penggajian</h2>
    <div class="field"><label for="pr">Gaji bulan</label><input id="pr" name="periode" type="month" required value="{{ old('periode', $usulanPeriode->format('Y-m')) }}"></div>
    <div class="field"><label for="tt">Tanggal transfer</label><input id="tt" name="tanggal_transfer" type="date" required value="{{ old('tanggal_transfer', $usulanTanggal->toDateString()) }}">
      <span class="hint">Ditulis di baris pertama berkas BSI.</span></div>
    <div class="field"><label for="ps">Pesan di rekening penerima</label><input id="ps" name="pesan" type="text" maxlength="65" value="{{ old('pesan') }}" placeholder="Gaji {{ $usulanPeriode->translatedFormat('F Y') }}">
      <span class="hint">Maks. 65 karakter. Kosongkan untuk "Gaji &lt;bulan&gt;".</span></div>
    <fieldset class="field"><legend>Nominal awal</legend>
      <label class="cek"><input type="radio" name="sumber" value="sebelumnya" checked> Sama dengan penggajian sebelumnya</label>
      <label class="cek"><input type="radio" name="sumber" value="tetap"> Nominal tetap di data pegawai</label></fieldset>
    <p class="hint" style="margin:0">Berisi {{ $jumlahPegawai }} pegawai aktif. Nominal tiap orang bisa diubah setelahnya.</p>
    <div><button class="btn p" @disabled(! $jumlahPegawai)>Buat</button></div>
  </form>
</div>
@endsection
