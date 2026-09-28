@extends('layouts.staf')
@section('judul', 'Pengguna')
@section('halaman')
<div class="top"><div><h1>Pengguna</h1><p class="muted">
  @if ($tab === 'staf')Akun staf. Password awal dan hasil reset dikirim ke WhatsApp pemilik; tidak ada yang mengetik atau melihat password orang lain.
  @else Akun portal wali santri. Satu akun bisa punya beberapa anak; wali ditambahkan dari halaman data santri.@endif
</p></div>
@if ($bolehStaf)
  <div class="seg" role="group" aria-label="Jenis akun">
    <a class="segbtn" href="{{ route('pengguna.index') }}" aria-pressed="{{ $tab === 'staf' ? 'true' : 'false' }}">Staf</a>
    <a class="segbtn" href="{{ route('pengguna.index', ['tab' => 'wali']) }}" aria-pressed="{{ $tab === 'wali' ? 'true' : 'false' }}">Wali santri</a>
  </div>
@endif
</div>
@error('pengguna')<div class="alert err" role="alert">{{ $message }}</div>@enderror
@unless ($waOn)<div class="note warn" style="margin-bottom:14px">WhatsApp belum diatur, jadi password tidak bisa dikirim. Sementara, reset dari server: <code>php artisan khandaq:reset-password &lt;username&gt;</code>.</div>@endunless

@if ($tab === 'staf')
<div class="grid" style="grid-template-columns:minmax(0,1fr) 320px;align-items:start">
  <div class="card"><div class="tw"><table><thead><tr><th>Nama</th><th>Peran</th><th>Status</th><th></th></tr></thead><tbody>
  @foreach ($staf as $u)
    <tr><td>{{ $u->name }}<br><span class="hint">{{ $u->username }} · {{ $u->telepon ?? 'tanpa WA' }}</span></td>
      <td>@if ($u->is(auth()->user())){{ $peran[$u->roles->first()?->name] ?? '—' }} <span class="hint">(Anda)</span>@else
        <form method="post" action="{{ route('pengguna.peran', $u) }}">@csrf<select name="peran" onchange="this.form.submit()" aria-label="Peran {{ $u->name }}">
          @unless ($u->roles->first())<option value="">Tanpa peran</option>@endunless
          @foreach ($peran as $k => $l)<option value="{{ $k }}" @selected($u->hasRole($k))>{{ $l }}</option>@endforeach</select></form>@endif</td>
      <td>@if ($u->aktif)<span class="chip c-good">Aktif</span>@else<span class="chip c-crit">Nonaktif</span>@endif</td>
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
@else
<form class="card form-baris" method="get" action="{{ route('pengguna.index') }}" style="margin-bottom:14px">
  <input type="hidden" name="tab" value="wali">
  <div class="field" style="flex:1 1 260px"><label for="q">Cari</label><input id="q" name="q" type="search" value="{{ $q }}" placeholder="Nama wali, username, nomor WA, nama/NIS santri"></div>
  <div class="field"><label for="st">Status</label><select id="st" name="status" style="width:auto">
    @foreach ($statusWali as $k => $l)<option value="{{ $k }}" @selected($status === $k)>{{ $l }} ({{ number_format($jumlah[$k], 0, ',', '.') }})</option>@endforeach
  </select></div>
  <div><button class="btn p">Tampilkan</button>@if ($q !== '' || $status !== 'semua') <a class="btn" href="{{ route('pengguna.index', ['tab' => 'wali']) }}">Reset</a>@endif</div>
</form>
@unless ($bolehKelolaWali)<div class="note info" style="margin-bottom:14px">Reset password dan nonaktifkan akun wali dilakukan oleh pemegang izin "Reset sandi &amp; nonaktifkan akun wali" (bawaan: Admin Office). Izin bisa dipindah di Hak akses.</div>@endunless
<div class="card"><div class="tw"><table><thead><tr><th>Wali</th><th>Anak</th><th>Akun</th>@if ($bolehKelolaWali)<th></th>@endif</tr></thead><tbody>
@forelse ($wali as $w)
  <tr>
    <td>{{ $w->name }}<br><span class="hint">{{ $w->username ?? 'tanpa username' }} · {{ $w->telepon ?: 'tanpa WA' }}</span>
      @if ($w->telepon_menunggu)<br><span class="hint">Nomor baru menunggu verifikasi: {{ $w->telepon_menunggu }}</span>@endif</td>
    <td>@forelse ($w->anak as $s)
        <div>@if ($bolehLihatSantri)<a href="{{ route('santri.show', $s) }}">{{ $s->nama }}</a>@else{{ $s->nama }}@endif
          <span class="hint">{{ $s->riwayatKelas->first()?->kelas?->nama ?? '—' }} · {{ ucfirst($s->pivot->hubungan) }}@if ($s->status?->value !== 'aktif') · {{ $s->status?->value }}@endif</span></div>
      @empty<span class="hint">Tidak ada anak tertaut</span>@endforelse</td>
    <td>@if ($w->aktif)<span class="chip c-good">Aktif</span>@else<span class="chip c-crit">Nonaktif</span>@endif
      @if ($w->password_lama)<br><span class="hint">Masih memakai password aplikasi lama</span>@elseif ($w->wajib_ganti_password)<br><span class="hint">Belum mengganti password awal</span>@endif</td>
    @if ($bolehKelolaWali)
    <td class="r"><div class="row" style="justify-content:flex-end">
      <form method="post" action="{{ route('pengguna.wali.reset', $w) }}" onsubmit="return confirm('Reset password {{ addslashes($w->name) }}? Password baru dikirim ke WhatsApp {{ $w->telepon }}.')">@csrf<button class="btn sm" @disabled(! $w->telepon)>Reset password</button></form>
      <form method="post" action="{{ route('pengguna.wali.aktif', $w) }}">@csrf<button class="btn sm">{{ $w->aktif ? 'Nonaktifkan' : 'Aktifkan' }}</button></form>
    </div></td>
    @endif
  </tr>
@empty
  <tr><td colspan="4" class="hint">Tidak ada akun wali yang cocok.</td></tr>
@endforelse
</tbody></table></div>{{ $wali->links() }}</div>
@endif
@endsection
