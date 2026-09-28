@extends('layouts.staf')
@section('judul', 'Data santri & wali')
@section('halaman')
<div class="top"><div><h1>Data santri & wali</h1><p class="muted">Tahun ajaran {{ $ta->nama }} · {{ $santri->total() }} santri ditampilkan</p></div>
  <div class="row"><a class="btn" href="{{ route('kenaikan.index') }}">Kenaikan kelas</a><a class="btn p" href="{{ route('santri.create') }}">Tambah santri</a></div></div>
@error('santri')<div class="alert err" role="alert">{{ $message }}</div>@enderror

@if ($nomorMenunggu->isNotEmpty())
<div class="card" style="margin-bottom:16px">
  <div class="card-h"><h2>Perubahan nomor WhatsApp menunggu verifikasi</h2><span class="chip c-warn">{{ $nomorMenunggu->count() }}</span></div>
  <p class="hint" style="margin-bottom:8px">Nomor ini dipakai untuk pemberitahuan resmi (tunggakan, pemulangan). Pastikan dengan menelepon nomor lama atau bertemu langsung sebelum menyetujui.</p>
  <div class="tw"><table><thead><tr><th>Wali</th><th>Anak</th><th>Nomor lama</th><th>Nomor baru</th><th></th></tr></thead><tbody>
  @foreach ($nomorMenunggu as $w)
    <tr><td>{{ $w->name }}</td><td>{{ $w->anak->pluck('nama')->join(', ') }}</td><td class="num">{{ $w->telepon ?? '—' }}</td><td class="num"><b>{{ $w->telepon_menunggu }}</b></td>
      <td class="r"><div class="row" style="justify-content:flex-end">
        <form method="post" action="{{ route('wali.telepon.setujui', $w) }}">@csrf<button class="btn p sm">Setujui</button></form>
        <form method="post" action="{{ route('wali.telepon.tolak', $w) }}">@csrf<button class="btn sm">Tolak</button></form></div></td></tr>
  @endforeach
  </tbody></table></div>
</div>
@endif

<form class="row" method="get" style="margin-bottom:14px">
  <input type="search" name="q" value="{{ $q }}" placeholder="Nama, NIS, atau kode transfer" style="max-width:260px" aria-label="Cari">
  <select name="kelas" style="width:auto" aria-label="Kelas"><option value="">Semua kelas</option>@foreach ($daftarKelas as $k)<option value="{{ $k->id }}" @selected($kelasId === $k->id)>{{ $k->nama }}</option>@endforeach</select>
  <select name="status" style="width:auto" aria-label="Status">@foreach (['aktif' => 'Aktif', 'calon' => 'Calon', 'alumni' => 'Alumni', 'keluar' => 'Keluar', 'semua' => 'Semua status'] as $v => $l)<option value="{{ $v }}" @selected($status === $v)>{{ $l }}</option>@endforeach</select>
  <button class="btn">Tampilkan</button>
</form>

<div class="card"><div class="tw"><table>
  <thead><tr><th>Santri</th><th>Kelas</th><th>Kode</th><th>Wali</th><th>Status</th></tr></thead>
  <tbody>
  @forelse ($santri as $s)
    <tr>
      <td><a href="{{ route('santri.show', $s) }}">{{ $s->nama }}</a><br><span class="hint">NIS {{ $s->nis }}</span></td>
      <td>{{ $s->riwayatKelas->first()?->kelas?->nama ?? '—' }}</td>
      <td class="num">{{ $s->kode_unik ?? '—' }}</td>
      <td>@forelse ($s->wali as $w){{ $w->name }}<span class="hint"> · {{ $w->pivot->hubungan }}{{ $w->telepon ? '' : ' · tanpa nomor' }}</span><br>@empty<span class="chip c-warn">Belum ada wali</span>@endforelse</td>
      <td><span class="chip {{ ['aktif' => 'c-good', 'calon' => 'c-info', 'keluar' => 'c-crit'][$s->status->value] ?? '' }}">{{ ucfirst($s->status->value) }}</span></td>
    </tr>
  @empty
    <tr><td colspan="5" class="muted">Tidak ada santri untuk filter ini.</td></tr>
  @endforelse
  </tbody>
</table></div>{{ $santri->links() }}</div>
@endsection
