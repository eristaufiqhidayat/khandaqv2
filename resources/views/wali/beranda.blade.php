@extends('layouts.wali')
@section('judul', 'Portal wali')
@section('judul-bar', 'Menu Utama')
@section('wali')
<p class="wb-sapa">Assalamu'alaikum, <b>{{ auth()->user()->name }}</b></p>
<nav class="wb-grid" aria-label="Menu utama">
  <a class="wb-tile" href="{{ route('wali.raport') }}">
    <svg viewBox="0 0 48 48" aria-hidden="true"><rect x="12" y="3" width="24" height="42" rx="4" fill="#111"/><rect x="16" y="9" width="16" height="28" rx="1.5" fill="#fff"/><rect x="19" y="14" width="10" height="3" fill="#111"/><rect x="19" y="20" width="10" height="3" fill="#111"/><rect x="19" y="26" width="7" height="3" fill="#111"/></svg>
    <span class="lbl">Raport</span></a>
  <a class="wb-tile" href="{{ route('wali.tabungan') }}">
    <svg viewBox="0 0 48 48" aria-hidden="true"><rect x="8" y="4" width="32" height="40" rx="4" fill="#2196F3"/><path d="M15 4h10v16l-5-4-5 4z" fill="#fff"/></svg>
    <span class="lbl">Tabungan</span></a>
  <a class="wb-tile" href="{{ route('wali.kalender') }}">
    <svg viewBox="0 0 48 48" aria-hidden="true"><rect x="5" y="8" width="38" height="36" rx="4" fill="#607D8B"/><rect x="12" y="3" width="4" height="10" rx="1.5" fill="#607D8B"/><rect x="32" y="3" width="4" height="10" rx="1.5" fill="#607D8B"/><rect x="9" y="17" width="30" height="23" rx="1" fill="#fff"/><g fill="#607D8B"><rect x="12" y="21" width="6" height="5"/><rect x="21" y="21" width="6" height="5"/><rect x="30" y="21" width="6" height="5"/><rect x="12" y="30" width="6" height="5"/><rect x="21" y="30" width="6" height="5"/><rect x="30" y="30" width="6" height="5"/></g></svg>
    <span class="lbl">Kalender</span></a>
  <a class="wb-tile" href="{{ route('wali.data') }}">
    <svg viewBox="0 0 48 48" aria-hidden="true"><rect x="8" y="4" width="32" height="40" rx="4" fill="#F44336"/><path d="M15 4h10v16l-5-4-5 4z" fill="#fff"/></svg>
    <span class="lbl">Data</span></a>
</nav>
<div class="card wb-anak">
  <div class="eyebrow">Santri</div>
  @forelse ($anak as $s)
    <div class="nama-foto">@include('santri._foto', ['s' => $s])<div><b>{{ $s->nama }}</b><br><span class="hint">NIS {{ $s->nis }} · kode transfer {{ $s->kode_unik ?? '—' }}</span></div></div>
  @empty
    <p class="muted">Akun Anda belum ditautkan ke santri. Hubungi Admin Office.</p>
  @endforelse
</div>
@endsection
