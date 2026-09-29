@extends('layouts.staf')
@section('judul', 'Ubah pegawai')
@section('halaman')
<div class="top"><div><h1>{{ $pg->nama }}</h1><p class="muted">Perubahan rekening berlaku untuk penggajian yang masih draf. Penggajian yang sudah terkirim tidak berubah.</p></div>
  <a class="btn" href="{{ route('gaji.pegawai') }}">Kembali</a></div>
@if ($errors->any())<div class="alert err" role="alert">{{ $errors->first() }}</div>@endif
<form class="card stack" method="post" action="{{ route('gaji.pegawai.update', $pg) }}" style="max-width:520px">@csrf @method('put')
  @include('gaji._form-pegawai', ['pg' => $pg])
  <div class="field"><label for="ur">Urutan di berkas</label><input id="ur" name="urutan" type="number" min="0" max="9999" value="{{ old('urutan', $pg->urutan) }}"></div>
  <div class="field"><label for="ct">Catatan</label><input id="ct" name="catatan" type="text" maxlength="255" value="{{ old('catatan', $pg->catatan) }}"></div>
  <label class="cek"><input type="checkbox" name="aktif" value="1" @checked(old('aktif', $pg->aktif))> Aktif (ikut penggajian bulan berikutnya)</label>
  <div><button class="btn p">Simpan</button></div>
</form>
@endsection
