@extends('layouts.wali')
@section('judul', 'Profil saya')
@section('judul-bar', 'Profil')
@section('kembali', route('wali.data'))
@section('wali')
  @if ($errors->any())<div class="alert err" role="alert">{{ $errors->first() }}</div>@endif
  <form class="card stack" method="post" action="{{ route('wali.profil.update') }}">
    @csrf @method('put')
    <div class="field"><span class="lbl">Nama</span><div>{{ $wali->name }} <span class="hint">· perubahan nama lewat Admin Office</span></div></div>
    <div class="field"><label for="a">Alamat (wali)</label><textarea id="a" name="alamat" rows="2" maxlength="300">{{ old('alamat', $wali->alamat) }}</textarea></div>
    <div class="field"><label for="p">Pekerjaan</label><input id="p" name="pekerjaan" type="text" maxlength="100" value="{{ old('pekerjaan', $wali->pekerjaan) }}"></div>
    <div class="field"><label for="e">Email</label><input id="e" name="email" type="email" maxlength="100" value="{{ old('email', str_ends_with((string) $wali->email, '@wali.khandaq') ? '' : $wali->email) }}" placeholder="opsional"></div>
    <div class="field"><label for="t">Nomor WhatsApp</label><input id="t" name="telepon" type="tel" maxlength="20" value="{{ old('telepon', $wali->telepon_menunggu ?? $wali->telepon) }}" placeholder="08…">
      <span class="hint">@if ($wali->telepon_menunggu)Nomor {{ $wali->telepon_menunggu }} sedang menunggu verifikasi; pemberitahuan masih dikirim ke {{ $wali->telepon }}.@else Nomor baru perlu diverifikasi pondok sebelum dipakai untuk pemberitahuan.@endif</span></div>
    <div><button class="btn p">Simpan</button></div>
  </form>
@endsection
