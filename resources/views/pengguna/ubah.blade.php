@extends('layouts.staf')
@section('judul', 'Ubah pengguna')
@section('halaman')
@php($sendiri = $u->is(auth()->user()))
<div class="top"><div><h1>Ubah pengguna {{ $u->name }}</h1>
  <p class="muted">Peran diatur dari daftar Pengguna; izin tiap peran di menu Hak akses.</p></div>
  <a class="btn" href="{{ route('pengguna.index') }}">Kembali</a></div>
@error('pengguna')<div class="alert err" role="alert">{{ $message }}</div>@enderror
<form class="card stack" method="post" action="{{ route('pengguna.update', $u) }}" style="max-width:760px">
  @csrf @method('put')
  <div class="grid g2">
    <div class="field"><label for="n">Nama</label><input id="n" name="name" type="text" required maxlength="100" value="{{ old('name', $u->name) }}"></div>
    <div class="field"><label for="un">Username</label><input id="un" name="username" type="text" required maxlength="50" value="{{ old('username', $u->username) }}"></div>
    <div class="field"><label for="e">Email</label><input id="e" name="email" type="email" required maxlength="100" value="{{ old('email', $u->email) }}"></div>
    <div class="field"><label for="t">Nomor WhatsApp</label><input id="t" name="telepon" type="tel" maxlength="20" value="{{ old('telepon', $u->telepon) }}" placeholder="08…"></div>
  </div>
  <p class="hint" style="margin:0">Username, email, atau nomor WhatsApp bisa dipakai untuk masuk.</p>
  <div class="row"><button class="btn p">Simpan</button><a class="btn" href="{{ route('pengguna.index') }}">Batal</a></div>
</form>

@if ($sendiri)
  <div class="note info" style="max-width:760px;margin-top:16px">Password akun sendiri diganti lewat <a href="{{ route('password.ganti') }}">Ganti password</a>.</div>
@else
<form class="card stack" method="post" action="{{ route('pengguna.password', $u) }}" style="max-width:760px;margin-top:16px" autocomplete="off">
  @csrf
  <h2>Ganti password</h2>
  <p class="hint" style="margin:0">Untuk pengguna yang lupa password. Sampaikan password baru langsung ke pemiliknya.</p>
  @error('password')<div class="alert err" role="alert">{{ $message }}</div>@enderror
  <div class="grid g2">
    <div class="field"><label for="pw">Password baru</label><input id="pw" name="password" type="password" required minlength="8" maxlength="200" autocomplete="new-password"></div>
    <div class="field"><label for="pw2">Ulangi password baru</label><input id="pw2" name="password_confirmation" type="password" required minlength="8" maxlength="200" autocomplete="new-password"></div>
  </div>
  <label class="row" style="gap:8px"><input type="checkbox" name="wajib_ganti" value="1" checked> Wajib diganti saat masuk berikutnya</label>
  <div><button class="btn p" onclick="return confirm('Ganti password {{ addslashes($u->name) }}?')">Ganti password</button></div>
</form>
@endif
@endsection
