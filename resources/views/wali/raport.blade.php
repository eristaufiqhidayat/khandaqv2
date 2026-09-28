@extends('layouts.wali')
@section('judul', 'Raport')
@section('judul-bar', 'Raport')
@section('kembali', route('wali.beranda'))
@section('wali')
@forelse ($anak as $a)
  <section class="card wb-kartu">
    <div class="card-h"><div class="nama-foto">@include('santri._foto', ['s' => $a['santri']])<h2>{{ $a['santri']->nama }}</h2></div></div>
    @forelse ($a['raport'] as $r)
      <div class="ph-line"><span>{{ strtoupper($r['raport']->jenis) }} · {{ $r['raport']->semester->label }}
        @if ($r['terkunci'])<br><span class="hint">{{ $r['terkunci'] }}</span>@endif</span>
        @if ($r['terkunci'])<span class="chip c-crit" style="align-self:center">Tertahan</span>@else<a class="btn sm" style="align-self:center" href="{{ route('raport.lihat', $r['raport']) }}" target="_blank" rel="noopener">Buka PDF</a>@endif</div>
    @empty <p class="hint">Belum ada raport yang diterbitkan.</p> @endforelse
  </section>
@empty
  <div class="card"><p class="muted">Akun Anda belum ditautkan ke santri. Hubungi Admin Office.</p></div>
@endforelse
@endsection
