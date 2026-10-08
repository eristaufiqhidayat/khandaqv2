@extends('layouts.staf')
@section('judul', 'Rekap nilai')
@php($fmt = fn ($v) => $v === null ? '–' : str_replace('.', ',', (string) $v))
@section('halaman')
<div class="top"><div><h1>Rekap nilai</h1><p class="muted">Nilai akhir = {{ $k->pilih && $mode === 'mapel' ? $k->pilih->mapel->rumus() : 'bobot harian, UTS, dan UAS tiap mapel' }} (diatur Admin per mapel). Bila sebagian belum diisi, dihitung dari komponen yang ada.</p></div>
  @if ($k->pilih)<div class="row no-cetak">
    @if ($mode === 'mapel')<a class="btn" href="{{ route('guru.rekap.unduh', $k->query()) }}">Unduh CSV</a>@endif
    <button class="btn" type="button" onclick="window.print()">Cetak</button></div>@endif</div>
<div class="stack">
  <div class="no-cetak">@include('guru._filter', ['aksi' => route('guru.rekap.index'), 'sembunyi' => ['mode' => $mode], 'pakaiMapel' => $mode === 'mapel'])</div>

  @if (! $k->pilih)
    @include('guru._tanpa-tugas')
  @else
  <div class="seg no-cetak" role="group" aria-label="Tampilan rekap">
    <a class="segbtn" href="{{ route('guru.rekap.index', $k->query(['mode' => 'mapel'])) }}" aria-pressed="{{ $mode === 'mapel' ? 'true' : 'false' }}">Per mata pelajaran</a>
    <a class="segbtn" href="{{ route('guru.rekap.index', $k->query(['mode' => 'kelas'])) }}" aria-pressed="{{ $mode === 'kelas' ? 'true' : 'false' }}">Semua mapel di kelas</a>
  </div>

  @if ($mode === 'mapel')
  @php($mapel = $k->pilih->mapel)
  <div class="grid g4">
    <div class="card kpi"><span class="eyebrow">Sudah dinilai</span><div class="v">{{ $ringkas['dinilai'] }}/{{ $ringkas['jumlah'] }}</div><div class="s">Harian {{ $ringkas['terisi']['harian'] }} · UTS {{ $ringkas['terisi']['uts'] }} · UAS {{ $ringkas['terisi']['uas'] }}</div></div>
    <div class="card kpi"><span class="eyebrow">Rata-rata kelas</span><div class="v">{{ $fmt($ringkas['rata']) }}</div><div class="s">KKM {{ $mapel->kkm }}</div></div>
    <div class="card kpi"><span class="eyebrow">Tertinggi / terendah</span><div class="v">{{ $fmt($ringkas['tertinggi']) }} / {{ $fmt($ringkas['terendah']) }}</div></div>
    <div class="card kpi"><span class="eyebrow">Di bawah KKM</span><div class="v" @if ($ringkas['bawah_kkm']) style="color:var(--crit)" @endif>{{ $ringkas['bawah_kkm'] }}</div><div class="s">santri perlu remedial</div></div>
  </div>
  <div class="card">
    <div class="card-h"><h2>Kelas {{ $k->pilih->kelas->nama }} · {{ $mapel->nama }}</h2><span class="muted">{{ $k->semester->label }}@if ($k->pilih->guru) · {{ $k->pilih->guru->name }}@endif</span></div>
    <div class="tw"><table>
      <thead><tr><th>No</th><th>NIS</th><th>Nama siswa</th><th class="r">H1</th><th class="r">H2</th><th class="r">H3</th><th class="r">Tugas</th><th class="r">Rata harian</th><th class="r">UTS</th><th class="r">UAS</th><th class="r">Nilai akhir</th><th>Predikat</th><th>Status</th><th class="r">Peringkat</th></tr></thead>
      <tbody>
      @forelse ($baris as $i => $b)
        <tr><td class="num">{{ $i + 1 }}</td><td class="num">{{ $b['santri']->nis }}</td><td>{{ $b['santri']->nama }}</td>
          @foreach (\App\Models\Nilai::HARIAN as $kol)<td class="r num faint">{{ $b['nilai']->{$kol} ?? '–' }}</td>@endforeach
          <td class="r num">{{ $fmt($b['harian']) }}</td><td class="r num">{{ $b['uts'] ?? '–' }}</td><td class="r num">{{ $b['uas'] ?? '–' }}</td>
          <td class="r num"><b>{{ $fmt($b['akhir']) }}</b></td>
          <td>@if ($b['predikat'])<span class="chip {{ ['A' => 'c-good', 'B' => 'c-acc', 'C' => 'c-gold', 'D' => 'c-crit'][$b['predikat']] }}">{{ $b['predikat'] }}</span>@endif</td>
          <td>@if ($b['tuntas'] === true)<span class="chip c-good">Tuntas</span>@elseif ($b['tuntas'] === false)<span class="chip c-crit">Belum tuntas</span>@else<span class="hint">Belum dinilai</span>@endif</td>
          <td class="r num">{{ $b['peringkat'] ?? '–' }}</td></tr>
      @empty
        <tr><td colspan="14" class="muted">Belum ada santri aktif di kelas ini.</td></tr>
      @endforelse
      </tbody>
    </table></div>
    <p class="hint" style="margin-top:10px">Predikat: A ≥ 90, B ≥ 80, C ≥ KKM, D di bawah KKM.</p>
  </div>
  @else
  <div class="card">
    <div class="card-h"><h2>Kelas {{ $k->pilih->kelas->nama }} · semua mapel</h2><span class="muted">{{ $k->semester->label }} · urut dari rata-rata tertinggi</span></div>
    @if (! auth()->user()->hasPermissionTo(\App\Enums\Izin::NilaiLihatSemua->value))<p class="hint" style="margin-bottom:8px">Hanya mapel yang Anda ajar di kelas ini yang tampil.</p>@endif
    <div class="tw"><table>
      <thead><tr><th class="r">#</th><th>NIS</th><th>Nama siswa</th>@foreach ($mapelKelas as $m)<th class="r" title="KKM {{ $m->kkm }}">{{ $m->kode ?: $m->nama }}</th>@endforeach<th class="r">Rata-rata</th><th class="r">Di bawah KKM</th></tr></thead>
      <tbody>
      @forelse ($barisKelas as $i => $b)
        <tr><td class="r num">{{ $b['rata'] === null ? '–' : $i + 1 }}</td><td class="num">{{ $b['santri']->nis }}</td><td>{{ $b['santri']->nama }}</td>
          @foreach ($mapelKelas as $m)@php($v = $b['per'][$m->id])<td class="r num" @if ($v !== null && $v < $m->kkm) style="color:var(--crit);font-weight:700" @endif>{{ $fmt($v) }}</td>@endforeach
          <td class="r num"><b>{{ $fmt($b['rata']) }}</b></td><td class="r num">{{ $b['bawah'] ?: '–' }}</td></tr>
      @empty
        <tr><td colspan="{{ 5 + $mapelKelas->count() }}" class="muted">Belum ada santri aktif di kelas ini.</td></tr>
      @endforelse
      </tbody>
    </table></div>
  </div>
  @endif
  @endif
</div>
@endsection
