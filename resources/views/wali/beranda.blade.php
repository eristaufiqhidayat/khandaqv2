@extends('layouts.dasar')
@section('judul', 'Portal wali')
@php($rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.'))
@section('isi')
<div class="wali-wrap">
  <div class="wali-top">
    <div class="brand"><div class="mark"><img src="{{ asset('img/logo.png') }}" alt=""></div><div><b>Assalamu'alaikum, {{ auth()->user()->name }}</b><span>Portal wali santri · Khandaq</span></div></div>
    <div class="row">
      <a class="btn" href="{{ route('wali.profil') }}">Profil saya</a>
      <a class="btn" href="{{ route('password.ganti') }}">Ganti password</a>
      <form method="post" action="{{ route('logout') }}">@csrf<button class="btn">Keluar</button></form>
    </div>
  </div>
  @if (session('status'))<div class="alert ok" role="status">{{ session('status') }}</div>@endif
  @forelse ($anak as $a)
    <div class="card stack" style="margin-bottom:16px">
      <div class="card-h" style="margin:0"><div class="nama-foto">@include('santri._foto', ['s' => $a['santri']])<h2>{{ $a['santri']->nama }}</h2></div><span class="chip">Kode transfer {{ $a['santri']->kode_unik ?? '—' }}</span></div>
      <div><div class="eyebrow">Saldo tabungan</div><div class="rp" style="font-size:24px;font-weight:700">{{ $rp($a['saldo']) }}</div></div>
      <div>
        <h3 style="margin-bottom:6px">Tagihan terbuka</h3>
        @forelse ($a['tagihan'] as $t)
          <div class="ph-line"><span>{{ $t->keterangan }}<br><span class="hint">Jatuh tempo {{ $t->jatuh_tempo->translatedFormat('d M Y') }}</span></span>
          <span class="rp" style="{{ $t->jatuh_tempo->lt($sekarang) ? 'color:var(--crit)' : '' }}">{{ $rp($t->sisa()) }}</span></div>
        @empty <p class="hint">Semua tagihan lunas. Terima kasih.</p> @endforelse
        <p class="hint" style="margin-top:6px">Tagihan terpotong otomatis dari tabungan saat saldo cukup.</p>
      </div>
      <div>
        <h3 style="margin-bottom:6px">Raport</h3>
        @forelse ($a['raport'] as $r)
          <div class="ph-line"><span>{{ strtoupper($r['raport']->jenis) }} · {{ $r['raport']->semester->label }}</span>
          @if ($r['terkunci'])<span class="chip c-crit" title="{{ $r['terkunci'] }}">Tertahan</span>@else<a class="btn sm" href="{{ route('raport.lihat', $r['raport']) }}" target="_blank" rel="noopener">Buka PDF</a>@endif</div>
          @if ($r['terkunci'])<p class="hint">{{ $r['terkunci'] }}</p>@endif
        @empty <p class="hint">Belum ada raport yang diterbitkan.</p> @endforelse
      </div>
    </div>
  @empty
    <div class="card"><p class="muted">Akun Anda belum ditautkan ke santri. Hubungi Admin Office.</p></div>
  @endforelse
</div>
@endsection
