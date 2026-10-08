@extends('layouts.staf')
@section('judul', 'Dashboard guru')
@php($fmt = fn ($v) => $v === null ? '–' : str_replace('.', ',', (string) $v))
@section('halaman')
<div class="top"><div><h1>Dashboard guru</h1><p class="muted">Assalamu'alaikum, {{ auth()->user()->name }}. {{ \Carbon\CarbonImmutable::today()->translatedFormat('l, d F Y') }} · {{ $k->semester->label }}</p></div>
  <form method="get" class="row"><label class="sr-only" for="d-sem">Semester</label>
    <select id="d-sem" name="semester" onchange="this.form.submit()" style="width:auto">@foreach ($k->ta->semester as $s)<option value="{{ $s->id }}" @selected($s->id === $k->semester->id)>{{ $s->label }}</option>@endforeach</select>
    <input type="hidden" name="ta" value="{{ $k->ta->id }}"></form></div>

<div class="stack">
  <div class="grid g4">
    <div class="card kpi"><span class="eyebrow">Kelas diajar</span><div class="v">{{ $k->penugasan->unique('kelas_id')->count() }}</div><div class="s">{{ $k->penugasan->count() }} kelas–mapel</div></div>
    <div class="card kpi"><span class="eyebrow">Santri</span><div class="v">{{ $jumlahSantri }}</div><div class="s">santri aktif di kelas Anda</div></div>
    <a class="card kpi klik" style="color:inherit;text-decoration:none" href="{{ route('guru.catatan.index') }}"><span class="eyebrow">Catatan harian</span><div class="v">{{ $catatanBulanIni }}</div><div class="s">bulan ini · @if ($catatanHariIni)<span style="color:var(--good)">hari ini sudah diisi</span>@else<span style="color:var(--warn)">hari ini belum diisi</span>@endif</div></a>
    <a class="card kpi klik" style="color:inherit;text-decoration:none" href="{{ route('guru.soal.index') }}"><span class="eyebrow">Bank soal</span><div class="v">{{ $soalBank }}</div><div class="s">{{ $soalSaya }} soal buatan Anda</div></a>
  </div>

  @if ($k->penugasan->isEmpty())
    @include('guru._tanpa-tugas')
  @else
  <div class="card">
    <div class="card-h"><h2>Kelas yang saya ajar</h2><span class="muted">{{ $k->ta->nama }}</span></div>
    <div class="tw"><table>
      <thead><tr><th>Kelas</th><th>Mata pelajaran</th><th class="r">Santri</th><th>Input nilai</th><th class="r">Rata-rata</th><th class="r">&lt; KKM</th><th></th></tr></thead>
      <tbody>
      @foreach ($kelas as $x)
        @php($g = $x['tugas'])@php($r = $x['r'])
        @php($q = ['ta' => $k->ta->id, 'semester' => $k->semester->id, 'kelas' => $g->kelas_id, 'mapel' => $g->mapel_id])
        <tr><td><b>{{ $g->kelas->nama }}</b></td><td>{{ $g->mapel->nama }} <span class="hint">KKM {{ $g->mapel->kkm }}</span></td><td class="r num">{{ $r['jumlah'] }}</td>
          <td><div class="row" style="gap:6px">
            @foreach (['harian' => 'Harian', 'uts' => 'UTS', 'uas' => 'UAS'] as $kode => $lbl)
              @php($isi = $r['terisi'][$kode])
              <a class="chip {{ $r['jumlah'] && $isi >= $r['jumlah'] ? 'c-good' : ($isi ? 'c-warn' : '') }}" style="text-decoration:none" href="{{ route('guru.nilai.index', $q + ['tab' => $kode]) }}" title="{{ $lbl }}: {{ $isi }} dari {{ $r['jumlah'] }} santri">{{ $lbl }} {{ $isi }}/{{ $r['jumlah'] }}</a>
            @endforeach</div></td>
          <td class="r num">{{ $fmt($r['rata']) }}</td>
          <td class="r num" @if ($r['bawah_kkm']) style="color:var(--crit);font-weight:700" @endif>{{ $r['bawah_kkm'] }}</td>
          <td class="r"><div class="row" style="gap:6px;justify-content:flex-end"><a class="btn sm p" href="{{ route('guru.nilai.index', $q) }}">Input nilai</a><a class="btn sm" href="{{ route('guru.rekap.index', $q) }}">Rekap</a></div></td></tr>
      @endforeach
      </tbody>
    </table></div>
  </div>
  @endif

  <div class="grid g2" style="align-items:start">
    <div class="card">
      <div class="card-h"><h2>Catatan harian terakhir</h2><a class="btn sm p" href="{{ route('guru.catatan.index') }}">Tulis catatan</a></div>
      @forelse ($catatanTerakhir as $c)
        <div class="ph-line"><span><b>{{ $c->materi }}</b><br><span class="hint">{{ $c->tanggal->translatedFormat('D, d M Y') }} · Kelas {{ $c->nama_kelas ?? '–' }}@if ($c->mapel) · {{ $c->mapel->nama }}@endif</span></span></div>
      @empty
        <p class="muted">Belum ada catatan. Catat materi & kejadian kelas tiap selesai mengajar.</p>
      @endforelse
    </div>
    <div class="card">
      <div class="card-h"><h2>Kegiatan pondok terdekat</h2></div>
      @forelse ($kegiatan as $kg)
        <div class="ph-line"><span><b>{{ $kg->kegiatan }}</b><br><span class="hint">{{ $kg->rentang() }}</span></span></div>
      @empty
        <p class="muted">Tidak ada kegiatan di kalender akademik dalam 45 hari ke depan.</p>
      @endforelse
    </div>
  </div>
</div>
@endsection
