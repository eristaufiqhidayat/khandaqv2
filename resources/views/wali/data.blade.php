@extends('layouts.wali')
@section('judul', 'Data')
@section('judul-bar', 'Data')
@section('kembali', route('wali.beranda'))
@section('wali')
@foreach ($anak as $s)
  <section class="card wb-kartu">
    <div style="display:flex;gap:14px;align-items:center;margin-bottom:10px">@include('santri._foto', ['s' => $s, 'ukuran' => 'lg'])
      <div><h2>{{ $s->nama }}</h2><span class="hint">{{ ucfirst($s->pivot->hubungan) }} dari santri ini</span></div></div>
    <div class="tw"><table><tbody>
      <tr><td class="hint">NIS / NISN</td><td class="num">{{ $s->nis }} / {{ $s->nisn ?? '—' }}</td></tr>
      <tr><td class="hint">Kelas</td><td>{{ $s->riwayatKelas->first()?->kelas?->nama ?? '—' }}</td></tr>
      <tr><td class="hint">Jenis kelamin</td><td>{{ ucfirst($s->jenis_kelamin) }}</td></tr>
      <tr><td class="hint">Tempat, tanggal lahir</td><td>{{ $s->tempat_lahir ?? '—' }}{{ $s->tanggal_lahir ? ', '.$s->tanggal_lahir->translatedFormat('d F Y') : '' }}</td></tr>
      <tr><td class="hint">Tanggal masuk</td><td>{{ $s->tanggal_masuk?->translatedFormat('d F Y') ?? '—' }}</td></tr>
      <tr><td class="hint">Alamat</td><td>{{ $s->alamat ?? '—' }}</td></tr>
      <tr><td class="hint">Kode transfer</td><td class="num">{{ $s->kode_unik ?? '—' }}</td></tr>
    </tbody></table></div>
    <p class="hint" style="margin-top:6px">Ada data santri yang keliru? Hubungi Admin Office.</p>
  </section>
@endforeach
<section class="card wb-kartu">
  <div class="card-h"><h2>Data wali</h2><a class="btn sm" href="{{ route('wali.profil') }}">Ubah</a></div>
  <div class="tw"><table><tbody>
    <tr><td class="hint">Nama</td><td>{{ $wali->name }}</td></tr>
    <tr><td class="hint">Nomor WhatsApp</td><td class="num">{{ $wali->telepon ?? '—' }}@if ($wali->telepon_menunggu)<br><span class="hint">Nomor baru {{ $wali->telepon_menunggu }} menunggu verifikasi</span>@endif</td></tr>
    <tr><td class="hint">Email</td><td>{{ str_ends_with((string) $wali->email, '@wali.khandaq') ? '—' : $wali->email }}</td></tr>
    <tr><td class="hint">Pekerjaan</td><td>{{ $wali->pekerjaan ?? '—' }}</td></tr>
    <tr><td class="hint">Alamat</td><td>{{ $wali->alamat ?? '—' }}</td></tr>
  </tbody></table></div>
</section>
@endsection
