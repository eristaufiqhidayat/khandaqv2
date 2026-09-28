@extends('layouts.dasar')
@section('judul', 'Masuk')
@section('isi')
<div class="masuk">
  <div class="kotak">
    <div class="logo">
      <img src="{{ asset('img/logo.png') }}" alt="Logo Pesantren Modern Lembah Arafah">
      <h1>Khandaq</h1>
      <small>Tabungan Santri · Pesantren Modern Lembah Arafah</small>
    </div>
    <form method="post" action="{{ route('login.store') }}">
      @csrf
      <div class="field">
        <label for="username">Username, email, atau nomor WhatsApp</label>
        <input id="username" name="username" type="text" value="{{ old('username') }}" autocomplete="username" required autofocus>
      </div>
      <div class="field">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" autocomplete="current-password" required>
      </div>
      @error('username')<p class="galat" role="alert">{{ $message }}</p>@enderror
      <label class="cek"><input type="checkbox" name="ingat" value="1"> Ingat saya di perangkat ini</label>
      <button class="btn p">Masuk</button>
      <p class="hint" style="text-align:center">Lupa password? Hubungi Admin Office untuk reset. Password baru dikirim ke WhatsApp Anda.</p>
    </form>
  </div>
</div>
@endsection
