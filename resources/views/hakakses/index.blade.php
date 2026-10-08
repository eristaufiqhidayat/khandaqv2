@extends('layouts.staf')
@section('judul', 'Hak akses')
@php($namaPeran = ['admin' => 'Admin', 'admin_office' => 'Admin Office', 'keuangan' => 'Keuangan', 'guru' => 'Guru'])
@section('halaman')
<div class="top"><div><h1>Hak akses</h1><p class="muted">Menu mengikuti izin. Contoh: pindahkan "Tarif" ke Keuangan dengan mencentang di kolom Keuangan lalu menghapus centang di kolom Admin. Semua perubahan tercatat.</p></div></div>
@error('hakakses')<div class="alert err" role="alert">{{ $message }}</div>@enderror
<div class="card"><div class="tw"><table>
  <thead><tr><th>Izin</th>@foreach ($matriks as $peran => $_)<th style="text-align:center">{{ $namaPeran[$peran] ?? $peran }}</th>@endforeach</tr></thead>
  <tbody>
  @foreach ($izin as $i)
    <tr><td>{{ $i->label() }}<br><span class="hint num">{{ $i->value }}</span></td>
    @foreach ($matriks as $peran => $punya)
      @php($ada = in_array($i->value, $punya, true))
      <td style="text-align:center">
        <form method="post" action="{{ route('hakakses.update') }}">@csrf
          <input type="hidden" name="peran" value="{{ $peran }}"><input type="hidden" name="izin" value="{{ $i->value }}"><input type="hidden" name="beri" value="{{ $ada ? 0 : 1 }}">
          <button class="btn sm" style="min-width:42px;{{ $ada ? 'background:var(--good-soft);color:var(--good);border-color:var(--good-soft)' : '' }}" aria-label="{{ $ada ? 'Cabut' : 'Beri' }} {{ $i->label() }} untuk {{ $namaPeran[$peran] ?? $peran }}">{{ $ada ? '✓' : '·' }}</button>
        </form></td>
    @endforeach</tr>
  @endforeach
  </tbody>
</table></div></div>
@endsection
