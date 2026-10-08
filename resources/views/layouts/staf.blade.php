@extends('layouts.dasar')
@section('isi')
<div class="app">
  <aside class="side">
    <div class="brand">
      <div class="mark"><img src="{{ asset('img/logo.png') }}" alt=""></div>
      <div><b>Khandaq</b><span>Tabungan Santri<br>Pesantren Modern Lembah Arafah</span></div>
    </div>
    <nav aria-label="Menu">
      @php($grupSebelum = null)
      @foreach (\App\Support\MenuStaf::untuk(auth()->user()) as $m)
        @if (($m['grup'] ?? null) && $m['grup'] !== $grupSebelum)<span class="eyebrow nav-grup">{{ $m['grup'] }}</span>@endif
        @php($grupSebelum = $m['grup'] ?? null)
        <a href="{{ route($m['route']) }}" @if (request()->routeIs(str_ends_with($m['route'], '.index') ? \Illuminate\Support\Str::beforeLast($m['route'], '.').'.*' : $m['route'])) aria-current="page" @endif>{{ $m['label'] }}</a>
      @endforeach
    </nav>
    <div class="side-foot">
      <b>{{ auth()->user()->name }}</b><br>
      {{ auth()->user()->getRoleNames()->map(fn ($r) => str_replace('_', ' ', $r))->join(', ') ?: 'tanpa peran' }}
      <div class="row" style="margin-top:8px;gap:14px">
        <a class="keluar" href="{{ route('password.ganti') }}">Ganti password</a>
        <form method="post" action="{{ route('logout') }}">@csrf<button class="keluar">Keluar</button></form>
      </div>
    </div>
  </aside>
  <main>
    @if (session('status'))<div class="alert ok" role="status">{{ session('status') }}</div>@endif
    @if ($errors->any())<div class="alert err" role="alert">{{ $errors->first() }}</div>@endif
    @yield('halaman')
  </main>
</div>
@endsection
