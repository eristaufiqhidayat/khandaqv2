@extends('layouts.staf')
@section('judul', 'Kenaikan kelas')
@section('halaman')
<div class="top"><div><h1>Kenaikan kelas {{ $dari?->nama }} → {{ $ke->nama }}</h1><p class="muted">Petakan setiap kelas lama ke kelas baru. Kelas yang dibiarkan "tinggal kelas" tidak diproses dan bisa diatur manual.</p></div>
  <form method="get"><select name="ke" onchange="this.form.submit()" aria-label="Tahun ajaran tujuan" style="width:auto">@foreach ($daftarTa as $t)<option value="{{ $t->id }}" @selected($t->id === $ke->id)>Ke {{ $t->nama }}</option>@endforeach</select></form></div>
@error('kenaikan')<div class="alert err" role="alert">{{ $message }}</div>@enderror
@if (! $dari)
  <div class="card"><p class="muted">Tidak ada tahun ajaran sebelum {{ $ke->nama }}.</p></div>
@else
  @if ($sudah)<div class="note warn" style="margin-bottom:14px">{{ $sudah }} santri sudah punya kelas di {{ $ke->nama }}. Menjalankan lagi akan menimpa kelas mereka sesuai peta.</div>@endif
  <form class="card stack" method="post" action="{{ route('kenaikan.store') }}" onsubmit="return confirm('Proses kenaikan kelas? Santri yang lulus menjadi alumni dan kode transfernya dibebaskan.')">@csrf
    <input type="hidden" name="dari" value="{{ $dari->id }}"><input type="hidden" name="ke" value="{{ $ke->id }}">
    <div class="tw"><table><thead><tr><th>Kelas {{ $dari->nama }}</th><th class="r">Santri aktif</th><th>Menjadi di {{ $ke->nama }}</th></tr></thead><tbody>
    @foreach ($kelas as $k)
      @php($usul = $saran[$k->id] ?? null)
      <tr><td>{{ $k->nama }}</td><td class="r num">{{ $jumlah[$k->id] ?? 0 }}</td>
        <td><select name="peta[{{ $k->id }}]" aria-label="Kelas baru untuk {{ $k->nama }}">
          <option value="tinggal">Tinggal kelas / atur manual</option>
          @foreach ($kelas as $b)<option value="{{ $b->id }}" @selected($usul?->id === $b->id)>{{ $b->nama }}</option>@endforeach
          <option value="lulus" @selected(! $usul && $k->tingkat >= 6)>Lulus (alumni)</option>
        </select></td></tr>
    @endforeach
    </tbody></table></div>
    <div><button class="btn p">Proses kenaikan kelas</button></div>
  </form>
@endif
@endsection
