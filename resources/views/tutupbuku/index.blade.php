@extends('layouts.staf')
@section('judul', 'Tutup buku')
@php($rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.'))
@section('halaman')
<div class="top"><div><h1>Tutup buku</h1><p class="muted">Setelah ditutup, transaksi bertanggal bulan itu dan sebelumnya tidak bisa ditambah, diubah, atau dihapus.</p></div></div>
@error('tutupbuku')<div class="alert err" role="alert">{{ $message }}</div>@enderror
@if ($mode === 'paralel')
  <div class="note warn" style="margin-bottom:14px"><b>Masa paralel.</b> Tutup buku belum bisa dilakukan, karena data masih disalin ulang dari aplikasi lama dan tutup buku akan mengunci Sinkronisasi data lama secara permanen. Angka di bawah hanya pratinjau. Tutup buku aktif setelah <code>KHANDAQ_MODE=produksi</code>.</div>
@endif

<div class="grid g2" style="align-items:start">
  <div class="card stack">
    <div class="card-h" style="margin:0"><h2>{{ $bulan->translatedFormat('F Y') }}</h2>
      @if (! $bolehDitutup)<span class="chip">Bulan berjalan</span>@elseif ($pending)<span class="chip c-warn">{{ $pending }} setoran pending</span>@else<span class="chip c-good">Siap ditutup</span>@endif</div>
    @if ($pertama)
      <form method="get" class="field"><label for="pb">Tutup buku pertama mulai dari</label>
        <select id="pb" name="bulan" onchange="this.form.submit()">@foreach ($pilihanBulan as $b)<option value="{{ $b->format('Y-m') }}" @selected($b->equalTo($bulan))>{{ $b->translatedFormat('F Y') }}</option>@endforeach</select></form>
    @endif
    <div class="tw"><table><tbody>
      <tr><td>Saldo titipan wali (semua santri)</td><td class="r rp">{{ $rp($saldoTitipan) }}</td></tr>
      @foreach ($saldoRekening as $kode => $n)<tr><td>Menurut sistem: {{ $kode }}</td><td class="r rp">{{ $rp($n) }}</td></tr>@endforeach
    </tbody></table></div>
    <p class="hint">Cocokkan angka rekening dengan saldo bank dan kas fisik per akhir {{ $bulan->translatedFormat('F Y') }} sebelum menutup.</p>
    @if ($pending)<div class="note warn">Verifikasi atau tolak dulu {{ $pending }} setoran pending di <a href="{{ route('verifikasi.index') }}">Verifikasi setoran</a>.</div>@endif
    @if ($bolehDitutup && ! $pending && $mode !== 'paralel')
    <form method="post" action="{{ route('tutupbuku.store') }}" class="stack" onsubmit="return confirm('Tutup buku {{ $bulan->translatedFormat('F Y') }}? Tidak bisa dibatalkan.')">
      @csrf <input type="hidden" name="bulan" value="{{ $bulan->format('Y-m') }}">
      <div class="field"><label for="ct">Catatan (opsional)</label><input id="ct" name="catatan" type="text" maxlength="500" placeholder="mis. saldo bank cocok"></div>
      <label class="cek"><input type="checkbox" name="konfirmasi" value="1" required> Angka di atas sudah saya cocokkan dengan bank dan kas fisik.</label>
      <div><button class="btn p">Tutup buku {{ $bulan->translatedFormat('F Y') }}</button></div>
    </form>
    @endif
  </div>

  <div class="card">
    <div class="card-h"><h2>Riwayat</h2></div>
    <div class="tw"><table><thead><tr><th>Bulan</th><th class="r">Saldo titipan</th><th>Ditutup oleh</th></tr></thead><tbody>
    @forelse ($riwayat as $t)
      <tr><td>{{ $t->bulan->translatedFormat('F Y') }}</td><td class="r rp">{{ $rp($t->saldo_titipan) }}</td>
        <td>{{ $t->petugas?->name }}<br><span class="hint">{{ $t->created_at->translatedFormat('d M Y H:i') }}@if ($t->catatan) · {{ $t->catatan }}@endif</span></td></tr>
    @empty
      <tr><td colspan="3" class="muted">Belum pernah tutup buku.</td></tr>
    @endforelse
    </tbody></table></div>
  </div>
</div>
@endsection
