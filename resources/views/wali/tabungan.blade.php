@extends('layouts.wali')
@section('judul', 'Tabungan')
@section('judul-bar', 'Tabungan')
@section('kembali', route('wali.beranda'))
@php
  $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
  $label = ['setoran_transfer' => 'Setoran transfer', 'setoran_tunai' => 'Setoran tunai', 'penarikan_tunai' => 'Uang saku',
    'pembayaran_tagihan' => 'Pembayaran', 'koreksi' => 'Koreksi', 'transfer_dana' => 'Pindahan dana', 'pengembalian' => 'Pengembalian saldo', 'saldo_awal' => 'Saldo awal'];
@endphp
@section('wali')
@forelse ($anak as $a)
  <section class="card wb-kartu stack">
    <div class="card-h" style="margin:0"><div class="nama-foto">@include('santri._foto', ['s' => $a['santri']])<h2>{{ $a['santri']->nama }}</h2></div><span class="chip">Kode transfer {{ $a['santri']->kode_unik ?? '—' }}</span></div>
    <div><div class="eyebrow">Saldo tabungan</div><div class="rp wb-saldo">{{ $rp($a['saldo']) }}</div>
      <p class="hint">Transfer ke rekening pondok dengan menambahkan kode {{ $a['santri']->kode_unik ?? '—' }} di akhir nominal agar tercatat otomatis.</p>
      <a class="btn p sm" href="{{ route('wali.lapor', ['anak' => $a['santri']->id]) }}">Lapor transfer</a></div>
    <div>
      <h3 style="margin-bottom:4px">Tagihan belum lunas</h3>
      @forelse ($a['tagihan'] as $t)
        <div class="ph-line"><span>{{ $t->keterangan }}<br><span class="hint">Jatuh tempo {{ $t->jatuh_tempo->translatedFormat('d M Y') }}</span></span>
        <span class="rp" style="{{ $t->jatuh_tempo->lt($sekarang) ? 'color:var(--crit)' : '' }}">{{ $rp($t->sisa()) }}</span></div>
      @empty <p class="hint">Semua tagihan lunas. Terima kasih.</p> @endforelse
      <p class="hint" style="margin-top:4px">SPP, laundry, dan kesehatan terpotong otomatis dari tabungan saat saldo cukup.</p>
    </div>
    <div>
      <h3 style="margin-bottom:4px">Riwayat transaksi <span class="hint">(50 terakhir)</span></h3>
      @forelse ($a['mutasi'] as $m)
        @php($masuk = $m->arah->value === 'kredit')
        <div class="wb-mut"><span>{{ $label[$m->jenis->value] ?? ucfirst(str_replace('_', ' ', $m->jenis->value)) }}@if ($m->keterangan) · {{ $m->keterangan }}@endif
          <br><span class="hint">{{ $m->tanggal->translatedFormat('d M Y') }}@if ($m->status->value === 'pending') · <span class="chip c-warn">menunggu verifikasi</span>@endif</span></span>
          <span class="rp {{ $masuk ? 'wb-plus' : 'wb-min' }}">{{ $masuk ? '+' : '−' }}{{ $rp($m->nominal) }}</span></div>
      @empty <p class="hint">Belum ada transaksi.</p> @endforelse
    </div>
  </section>
@empty
  <div class="card"><p class="muted">Akun Anda belum ditautkan ke santri. Hubungi Admin Office.</p></div>
@endforelse
@endsection
