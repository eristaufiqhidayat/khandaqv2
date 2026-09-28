@extends('layouts.staf')
@section('judul', 'Pengguna')
@section('halaman')
<div class="top"><div><h1>Pengguna</h1><p class="muted">Akun staf. Password awal dan hasil reset dikirim ke WhatsApp pemilik; tidak ada yang mengetik atau melihat password orang lain.</p></div></div>
@error('pengguna')<div class="alert err" role="alert">{{ $message }}</div>@enderror
@unless ($waOn)<div class="note warn" style="margin-bottom:14px">WhatsApp belum diatur, jadi password tidak bisa dikirim. Sementara, reset dari server: <code>php artisan khandaq:reset-password &lt;username&gt;</code>.</div>@endunless
<div class="grid" style="grid-template-columns:minmax(0,1fr) 320px;align-items:start">
  <div class="card"><div class="tw"><table><thead><tr><th>Nama</th><th>Peran</th><th>Status</th><th></th></tr></thead><tbody>
  @foreach ($staf as $u)
    <tr><td>{{ $u->name }}<br><span class="hint">{{ $u->username }} · {{ $u->telepon ?? 'tanpa WA' }}</span></td>
      <td>@if ($u->is(auth()->user())){{ $peran[$u->roles->first()?->name] ?? '—' }} <span class="hint">(Anda)</span>@else
        <form method="post" action="{{ route('pengguna.peran', $u) }}">@csrf<select name="peran" onchange="this.form.submit()" aria-label="Peran {{ $u->name }}">
          @unless ($u->roles->first())<option value="">Tanpa peran</option>@endunless
          @foreach ($peran as $k => $l)<option value="{{ $k }}" @selected($u->hasRole($k))>{{ $l }}</option>@endforeach</select></form>@endif</td>
      <td>@if ($u->aktif)<span class="chip c-good">Aktif</span>@else<span class="chip c-crit">Nonaktif</span>@endif{{ $u->wajib_ganti_password ? '' : '' }}</td>
      <td class="r">@unless ($u->is(auth()->user()))<div class="row" style="justify-content:flex-end">
        <form method="post" action="{{ route('pengguna.reset', $u) }}" onsubmit="return confirm('Reset password {{ $u->name }}?')">@csrf<button class="btn sm">Reset password</button></form>
        <form method="post" action="{{ route('pengguna.aktif', $u) }}">@csrf<button class="btn sm">{{ $u->aktif ? 'Nonaktifkan' : 'Aktifkan' }}</button></form></div>@endunless</td></tr>
  @endforeach
  </tbody></table></div></div>
  <form class="card stack" method="post" action="{{ route('pengguna.store') }}">@csrf<h2>Tambah staf</h2>
    <div class="field"><label for="n">Nama</label><input id="n" name="name" type="text" required maxlength="100" value="{{ old('name') }}"></div>
    <div class="field"><label for="u">Username</label><input id="u" name="username" type="text" required maxlength="50" value="{{ old('username') }}"></div>
    <div class="field"><label for="e">Email</label><input id="e" name="email" type="email" required maxlength="100" value="{{ old('email') }}"></div>
    <div class="field"><label for="t">Nomor WhatsApp</label><input id="t" name="telepon" type="tel" required maxlength="20" value="{{ old('telepon') }}"></div>
    <div class="field"><label for="p">Peran</label><select id="p" name="peran">@foreach ($peran as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></div>
    <div><button class="btn p">Buat akun</button></div>
  </form>
</div>
@endsection
