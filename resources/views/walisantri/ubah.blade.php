@extends('layouts.staf')
@section('judul', 'Ubah data wali')
@section('halaman')
<div class="top"><div><h1>Ubah data wali {{ $wali->name }}</h1>
  <p class="muted">Username <b>{{ $wali->username ?? '—' }}</b> tidak berubah. Nomor WhatsApp yang diubah di sini langsung berlaku dan bisa dipakai untuk masuk.</p></div></div>
@error('wali')<div class="alert err" role="alert">{{ $message }}</div>@enderror
@if ($wali->telepon_menunggu)<div class="note warn" style="margin-bottom:14px">Wali mengajukan nomor baru <b>{{ $wali->telepon_menunggu }}</b>. Isi nomor itu di bawah untuk menyetujuinya, atau setujui/tolak dari daftar santri.</div>@endif
<form class="card stack" method="post" action="{{ route('walisantri.update', $wali) }}" style="max-width:760px">
  @csrf @method('put')
  <input type="hidden" name="kembali" value="{{ $kembali }}">
  <div class="grid g2">
    <div class="field"><label for="n">Nama</label><input id="n" name="name" type="text" required maxlength="100" value="{{ old('name', $wali->name) }}"></div>
    <div class="field"><label for="t">Nomor WhatsApp</label><input id="t" name="telepon" type="tel" required maxlength="20" value="{{ old('telepon', $wali->telepon) }}" placeholder="08…"></div>
    <div class="field"><label for="e">Email</label><input id="e" name="email" type="email" maxlength="100" value="{{ old('email', str_ends_with((string) $wali->email, '@wali.khandaq') ? '' : $wali->email) }}" placeholder="kosongkan bila tidak ada"></div>
    <div class="field"><label for="k">NIK</label><input id="k" name="nik" type="text" inputmode="numeric" maxlength="16" value="{{ old('nik', $wali->nik) }}"></div>
    <div class="field"><label for="p">Pekerjaan</label><input id="p" name="pekerjaan" type="text" maxlength="100" value="{{ old('pekerjaan', $wali->pekerjaan) }}"></div>
  </div>
  <div class="field"><label for="a">Alamat wali</label><textarea id="a" name="alamat" rows="2" maxlength="300">{{ old('alamat', $wali->alamat) }}</textarea></div>
  @if ($wali->anak->isNotEmpty())
  <div class="field"><span class="lbl">Hubungan dengan santri</span>
    <div class="stack" style="gap:6px">
    @foreach ($wali->anak as $s)
      <div class="form-baris"><label for="h{{ $s->id }}" style="min-width:220px">{{ $s->nama }} <span class="hint">NIS {{ $s->nis }}</span></label>
        <select id="h{{ $s->id }}" name="hubungan[{{ $s->id }}]" style="width:auto">@foreach (['ayah' => 'Ayah', 'ibu' => 'Ibu', 'wali' => 'Wali'] as $k => $l)<option value="{{ $k }}" @selected(old('hubungan.'.$s->id, $s->pivot->hubungan) === $k)>{{ $l }}</option>@endforeach</select></div>
    @endforeach
    </div></div>
  @endif
  <div class="row"><button class="btn p">Simpan</button><a class="btn" href="{{ $kembali }}">Batal</a></div>
</form>

@if ($bolehPassword)
<form class="card stack" method="post" action="{{ route('walisantri.password', $wali) }}" style="max-width:760px;margin-top:16px" autocomplete="off">
  @csrf <input type="hidden" name="kembali" value="{{ $kembali }}">
  <h2>Ganti password wali</h2>
  <p class="hint" style="margin:0">Untuk wali yang lupa password. Sampaikan password baru langsung ke wali. Aplikasi Android wali ini akan diminta masuk ulang.
    @if ($wali->telepon)Bila WhatsApp sudah aktif, <b>Reset password</b> di tab Wali santri mengirim password acak ke WA-nya tanpa perlu diketik.@endif</p>
  @error('password')<div class="alert err" role="alert">{{ $message }}</div>@enderror
  <div class="grid g2">
    <div class="field"><label for="pw">Password baru</label><input id="pw" name="password" type="password" required minlength="8" maxlength="200" autocomplete="new-password"></div>
    <div class="field"><label for="pw2">Ulangi password baru</label><input id="pw2" name="password_confirmation" type="password" required minlength="8" maxlength="200" autocomplete="new-password"></div>
  </div>
  <label class="row" style="gap:8px"><input type="checkbox" name="wajib_ganti" value="1" checked> Wajib diganti wali saat masuk berikutnya</label>
  <div><button class="btn p" onclick="return confirm('Ganti password {{ addslashes($wali->name) }}?')">Ganti password</button></div>
</form>
@endif
@endsection
