@extends('layouts.dasar')
@section('judul', 'Ganti password')
@section('isi')
<div class="masuk">
  <div class="kotak">
    <div class="logo">
      <img src="{{ asset('img/logo.png') }}" alt="">
      <h1>Ganti password</h1>
      <small>{{ $wajib ? 'Password Anda masih password sementara. Buat password baru sebelum melanjutkan.' : 'Minimal '.\App\Services\AkunService::PANJANG_MINIMAL.' karakter.' }}</small>
    </div>
    <form method="post" action="{{ route('password.ganti.simpan') }}">
      @csrf @method('put')
      <div class="field"><label for="pl">Password saat ini</label><input id="pl" name="password_lama" type="password" autocomplete="current-password" required></div>
      <div class="field"><label for="pb">Password baru</label><input id="pb" name="password" type="password" autocomplete="new-password" minlength="{{ \App\Services\AkunService::PANJANG_MINIMAL }}" required></div>
      <div class="field"><label for="pu">Ulangi password baru</label><input id="pu" name="password_confirmation" type="password" autocomplete="new-password" required></div>
      @if ($errors->any())<p class="galat" role="alert">{{ $errors->first() }}</p>@endif
      <button class="btn p">Simpan password</button>
      @unless ($wajib)<a class="hint" style="text-align:center" href="{{ route('beranda') }}">Batal</a>@endunless
    </form>
    <form method="post" action="{{ route('logout') }}" style="margin-top:10px;text-align:center">@csrf<button class="btn" style="border:0;background:none;color:var(--ink-2)">Keluar</button></form>
  </div>
</div>
@endsection
