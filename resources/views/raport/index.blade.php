@extends('layouts.staf')
@section('judul', 'Raport & dispensasi')
@php($jenisLabel = ['pts' => 'PTS', 'pas' => 'PAS', 'pat' => 'PAT'])
@section('halaman')
<div class="top"><div><h1>Raport & dispensasi</h1><p class="muted">Raport tertahan untuk wali bila SPP yang sudah jatuh tempo belum lunas, kecuali ada dispensasi yang disetujui Keuangan.</p></div></div>
@error('raport')<div class="alert err" role="alert">{{ $message }}</div>@enderror
@if ($errors->has('file'))<div class="alert err" role="alert">{{ $errors->first('file') }}</div>@endif

<form class="row" method="get" style="margin-bottom:14px">
  <select name="semester" style="width:auto" aria-label="Semester" onchange="this.form.submit()">@foreach ($daftarSemester as $s)<option value="{{ $s->id }}" @selected($semester?->id === $s->id)>{{ $s->label }}</option>@endforeach</select>
  <div class="seg" role="group" aria-label="Jenis">@foreach ($jenisLabel as $k => $l)<a class="segbtn" href="{{ route('raport.index', array_merge(request()->only(['semester', 'kelas']), ['jenis' => $k])) }}" aria-pressed="{{ $jenis === $k ? 'true' : 'false' }}">{{ $l }}</a>@endforeach</div>
  <input type="hidden" name="jenis" value="{{ $jenis }}">
  <select name="kelas" style="width:auto" aria-label="Kelas" onchange="this.form.submit()"><option value="">Semua kelas</option>@foreach ($daftarKelas as $k)<option value="{{ $k->id }}" @selected($kelasId === $k->id)>{{ $k->nama }}</option>@endforeach</select>
</form>

@php($tertahan = $baris->whereNotNull('terkunci')->count())
<div class="card">
  <div class="card-h"><div class="row"><span class="chip">{{ $baris->whereNotNull('raport')->count() }} / {{ $baris->count() }} diunggah</span>
    <span class="chip {{ $tertahan ? 'c-crit' : 'c-good' }}">{{ $tertahan }} tertahan</span></div></div>
  <div class="tw"><table>
    <thead><tr><th>Santri</th><th>Kelas</th><th>File {{ $jenisLabel[$jenis] }}</th><th>Untuk wali</th><th></th></tr></thead>
    <tbody>
    @forelse ($baris as $b)
      @php($r = $b['raport'])
      <tr>
        <td>{{ $b['santri']->nama }}</td><td>{{ $b['kelas'] ?? '—' }}</td>
        <td>@if ($r)<a href="{{ route('raport.lihat', $r) }}" target="_blank" rel="noopener">Lihat PDF</a><br><span class="hint">{{ $r->diterbitkan_pada ? 'terbit '.$r->diterbitkan_pada->translatedFormat('d M Y') : 'belum diterbitkan' }}</span>@else<span class="hint">Belum ada</span>@endif</td>
        <td>
          @if (! $r || ! $r->diterbitkan_pada)<span class="chip">Belum terbit</span>
          @elseif ($b['terkunci'])<span class="chip c-crit" title="{{ $b['terkunci'] }}">Tertahan</span><br><span class="hint">{{ $b['terkunci'] }}</span>
          @else<span class="chip c-good">Terbuka</span>@endif
        </td>
        <td class="r"><div class="row" style="justify-content:flex-end">
          <details class="aksi"><summary class="btn sm">{{ $r ? 'Ganti file' : 'Unggah' }}</summary>
            <form method="post" action="{{ route('raport.unggah', $b['santri']) }}" enctype="multipart/form-data" class="stack" style="margin-top:6px">@csrf
              <input type="hidden" name="semester_id" value="{{ $semester->id }}"><input type="hidden" name="jenis" value="{{ $jenis }}">
              <input name="file" type="file" accept=".pdf" required aria-label="File raport PDF">
              <label class="cek"><input type="checkbox" name="terbitkan" value="1" @checked(! $r || $r->diterbitkan_pada)> Langsung terbitkan ke wali</label>
              <button class="btn p sm">Unggah</button></form>
          </details>
          @if ($r)<form method="post" action="{{ route('raport.terbitkan', $r) }}">@csrf<button class="btn sm">{{ $r->diterbitkan_pada ? 'Tarik' : 'Terbitkan' }}</button></form>@endif
          @if ($b['terkunci'] && $bolehAjukan)
          <details class="aksi"><summary class="btn sm">Ajukan dispensasi</summary>
            <form method="post" action="{{ route('raport.dispensasi', $b['santri']) }}" class="stack" style="margin-top:6px">@csrf
              <input type="hidden" name="semester_id" value="{{ $semester->id }}">
              <div class="field"><label for="jb{{ $b['santri']->id }}">Janji bayar</label><input id="jb{{ $b['santri']->id }}" name="janji_bayar" type="date" required min="{{ now()->toDateString() }}"></div>
              <div class="field"><label for="al{{ $b['santri']->id }}">Alasan</label><input id="al{{ $b['santri']->id }}" name="alasan" type="text" required maxlength="500"></div>
              <button class="btn p sm">Kirim ke Keuangan</button></form>
          </details>
          @endif
        </div></td>
      </tr>
    @empty
      <tr><td colspan="5" class="muted">Tidak ada santri aktif untuk semester/kelas ini.</td></tr>
    @endforelse
    </tbody>
  </table></div>
</div>
@endsection
