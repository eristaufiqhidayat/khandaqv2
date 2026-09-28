@extends('layouts.staf')
@section('judul', $santri->exists ? 'Ubah biodata' : 'Tambah santri')
@section('halaman')
<div class="top"><div><h1>{{ $santri->exists ? 'Ubah biodata '.$santri->nama : 'Tambah santri' }}</h1>
  <p class="muted">{{ $santri->exists ? 'Kode transfer dan status tidak diubah di sini.' : 'Untuk santri pindahan atau yang tidak lewat Pendaftaran santri baru. Kode transfer dibuat otomatis.' }}</p></div></div>
@error('santri')<div class="alert err" role="alert">{{ $message }}</div>@enderror
<form class="card stack" method="post" action="{{ $santri->exists ? route('santri.update', $santri) : route('santri.store') }}" style="max-width:760px">
  @csrf @if ($santri->exists) @method('put') @endif
  <div class="grid g2">
    <div class="field"><label for="nama">Nama lengkap</label><input id="nama" name="nama" type="text" required maxlength="100" value="{{ old('nama', $santri->nama) }}"></div>
    <div class="field"><label for="nis">NIS</label><input id="nis" name="nis" type="text" required maxlength="20" value="{{ old('nis', $santri->nis) }}"></div>
    <div class="field"><label for="jk">Jenis kelamin</label><select id="jk" name="jenis_kelamin"><option value="laki-laki">Laki-laki</option><option value="perempuan" @selected(old('jenis_kelamin', $santri->jenis_kelamin) === 'perempuan')>Perempuan</option></select></div>
    @unless ($santri->exists)
    <div class="field"><label for="kelas">Kelas</label><select id="kelas" name="kelas_id" required><option value="">Pilih kelas</option>@foreach ($daftarKelas as $k)<option value="{{ $k->id }}" @selected((int) old('kelas_id') === $k->id)>{{ $k->nama }}</option>@endforeach</select></div>
    @endunless
    <div class="field"><label for="nisn">NISN</label><input id="nisn" name="nisn" type="text" maxlength="20" value="{{ old('nisn', $santri->nisn) }}"></div>
    <div class="field"><label for="nik">NIK</label><input id="nik" name="nik" type="text" inputmode="numeric" maxlength="16" value="{{ old('nik', $santri->nik) }}"></div>
    <div class="field"><label for="tl">Tempat lahir</label><input id="tl" name="tempat_lahir" type="text" maxlength="50" value="{{ old('tempat_lahir', $santri->tempat_lahir) }}"></div>
    <div class="field"><label for="tg">Tanggal lahir</label><input id="tg" name="tanggal_lahir" type="date" value="{{ old('tanggal_lahir', $santri->tanggal_lahir?->toDateString()) }}"></div>
    <div class="field"><label for="as">Asal sekolah</label><input id="as" name="asal_sekolah" type="text" maxlength="100" value="{{ old('asal_sekolah', $santri->asal_sekolah) }}"></div>
    <div class="field"><label for="tm">Tanggal masuk</label><input id="tm" name="tanggal_masuk" type="date" value="{{ old('tanggal_masuk', $santri->tanggal_masuk?->toDateString() ?? now()->toDateString()) }}"></div>
  </div>
  <div class="field"><label for="al">Alamat</label><textarea id="al" name="alamat" rows="2" maxlength="500">{{ old('alamat', $santri->alamat) }}</textarea></div>
  @if ($kodeSaran)<p class="hint">Kode transfer yang akan dipakai: <b>{{ $kodeSaran }}</b> (wali menambahkan angka ini di akhir nominal transfer).</p>@endif
  <div class="row"><button class="btn p">{{ $santri->exists ? 'Simpan' : 'Tambah santri' }}</button>
    <a class="btn" href="{{ $santri->exists ? route('santri.show', $santri) : route('santri.index') }}">Batal</a></div>
</form>
@endsection
