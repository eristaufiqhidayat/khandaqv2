@extends('layouts.dasar')
{{-- Portal wali bergaya aplikasi HP (seperti Khandaq v1): bilah biru dengan judul + menu garis tiga. --}}
@section('isi')
<header class="wb-bar">
  <button class="wb-burger" type="button" aria-label="Buka menu" aria-controls="wb-laci" aria-expanded="false" onclick="waliLaci(true)">
    <svg viewBox="0 0 24 24" width="26" height="26" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"/></svg>
  </button>
  <h1>@yield('judul-bar', 'Menu Utama')</h1>
  @hasSection('kembali')<a class="wb-kembali" href="@yield('kembali')" aria-label="Kembali ke menu utama"><svg viewBox="0 0 24 24" width="24" height="24" aria-hidden="true"><path d="M15 5l-7 7 7 7" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg></a>@else<span class="wb-kembali" aria-hidden="true"></span>@endif
</header>
<div class="wb-tirai" id="wb-tirai" hidden onclick="waliLaci(false)"></div>
<nav class="wb-laci" id="wb-laci" aria-label="Menu wali" hidden>
  <div class="wb-laci-h"><img src="{{ asset('img/logo.png') }}" alt="" width="44" height="44"><div><b>{{ auth()->user()->name }}</b><span>Portal wali santri · Khandaq</span></div></div>
  @php($r = request()->route()?->getName())
  @foreach (['wali.beranda' => 'Menu utama', 'wali.raport' => 'Raport', 'wali.tabungan' => 'Tabungan', 'wali.kalender' => 'Kalender', 'wali.data' => 'Data santri', 'wali.profil' => 'Profil saya'] as $rute => $label)
    <a href="{{ route($rute) }}" @if ($r === $rute) aria-current="page" @endif>{{ $label }}</a>
  @endforeach
  <a href="{{ route('password.ganti') }}">Ganti password</a>
  <form method="post" action="{{ route('logout') }}">@csrf<button type="submit">Keluar</button></form>
</nav>
<main class="wb-isi">
  @if (session('status'))<div class="alert ok" role="status">{{ session('status') }}</div>@endif
  @yield('wali')
</main>
<script>
function waliLaci(buka){
  var l=document.getElementById('wb-laci'),t=document.getElementById('wb-tirai'),b=document.querySelector('.wb-burger');
  l.hidden=!buka;t.hidden=!buka;b.setAttribute('aria-expanded',buka?'true':'false');
  if(buka){l.querySelector('a').focus();}
}
document.addEventListener('keydown',function(e){if(e.key==='Escape'){waliLaci(false);}});
</script>
@endsection
