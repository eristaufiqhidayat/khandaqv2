@extends('layouts.staf')
@section('judul', 'Tahun ajaran & kelas')
@section('halaman')
<div class="top"><div><h1>Tahun ajaran & kelas</h1><p class="muted">Tahun ajaran selalu 1 Juli – 30 Juni. Semester aktif menentukan raport dan laporan yang tampil.</p></div>
  <form method="post" action="{{ route('periode.siapkan') }}">@csrf<button class="btn">Siapkan tahun ajaran berikutnya</button></form></div>
<div class="grid g2" style="align-items:start">
  <div class="card"><div class="card-h"><h2>Tahun ajaran</h2></div><div class="tw"><table><thead><tr><th>Semester</th><th>Rentang</th><th></th></tr></thead><tbody>
  @foreach ($daftarTa as $ta)
    @foreach ($ta->semester as $s)
      <tr><td>{{ $s->nama }}</td><td class="num">{{ $s->mulai->translatedFormat('d M Y') }} – {{ $s->selesai->translatedFormat('d M Y') }}</td>
        <td class="r">@if ($s->aktif)<span class="chip c-good">Aktif</span>@else<form method="post" action="{{ route('periode.aktifkan', $s) }}" onsubmit="return confirm('Jadikan {{ $s->nama }} semester aktif?')">@csrf<button class="btn sm">Aktifkan</button></form>@endif</td></tr>
    @endforeach
  @endforeach
  </tbody></table></div></div>
  <div class="stack">
    <div class="card"><div class="card-h"><h2>Kelas</h2><a class="btn sm" href="{{ route('kenaikan.index') }}">Kenaikan kelas</a></div><div class="tw"><table><thead><tr><th>Kelas</th><th>Tingkat</th><th>Status</th><th></th></tr></thead><tbody>
    @foreach ($kelas as $k)
      <tr><td>{{ $k->nama }}</td><td>{{ $k->tingkat }}{{ $k->jenis_kelamin ? ' · '.$k->jenis_kelamin : '' }}</td><td>@if ($k->aktif)<span class="chip c-good">Aktif</span>@else<span class="chip">Nonaktif</span>@endif</td>
        <td class="r">@if ($bolehKelas)<form method="post" action="{{ route('periode.kelas.toggle', $k) }}">@csrf<button class="btn sm">{{ $k->aktif ? 'Nonaktifkan' : 'Aktifkan' }}</button></form>@endif</td></tr>
    @endforeach
    </tbody></table></div></div>
    @if ($bolehKelas)
    <form class="card stack" method="post" action="{{ route('periode.kelas.store') }}">@csrf<h2>Tambah kelas</h2>
      <div class="grid g2"><div class="field"><label for="kn">Nama</label><input id="kn" name="nama" type="text" required maxlength="20" placeholder="mis. 4 PUTRI"></div>
        <div class="field"><label for="kt">Tingkat</label><select id="kt" name="tingkat">@foreach (range(1, 6) as $t)<option>{{ $t }}</option>@endforeach</select></div></div>
      <div class="field"><label for="kj">Untuk</label><select id="kj" name="jenis_kelamin"><option value="">Campur</option><option value="putra">Putra</option><option value="putri">Putri</option></select></div>
      <div><button class="btn p">Tambah kelas</button></div></form>
    @endif
  </div>
</div>
@endsection
