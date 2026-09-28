@extends('layouts.staf')
@section('judul', 'Tarif')
@php($rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.'))
@section('halaman')
<div class="top"><div><h1>Tarif {{ $ta->nama }}</h1><p class="muted">Besaran menurut kebijakan. Tagihan yang sudah terbit tidak ikut berubah; perubahan di tengah tahun memakai tanggal berlaku mulai.</p></div>
  <form method="get" class="row"><select name="ta" onchange="this.form.submit()" aria-label="Tahun ajaran" style="width:auto">@foreach ($daftarTa as $t)<option value="{{ $t->id }}" @selected($t->id === $ta->id)>{{ $t->nama }}</option>@endforeach</select></form></div>
@error('tarif')<div class="alert err" role="alert">{{ $message }}</div>@enderror
@if ($tarif->isEmpty() && $sebelumnya)
  <form method="post" action="{{ route('tarif.salin', $ta) }}" class="note info row" style="margin-bottom:14px;justify-content:space-between">@csrf
    <span>Tarif {{ $ta->nama }} belum ada. Salin dari {{ $sebelumnya->nama }} lalu sesuaikan?</span><button class="btn p sm">Salin tarif {{ $sebelumnya->nama }}</button></form>
@endif

<div class="grid" style="grid-template-columns:minmax(0,1fr) 320px;align-items:start">
  <div class="card"><div class="tw"><table>
    <thead><tr><th>Jenis</th><th>Kelas</th><th>Berlaku mulai</th><th class="r">Nominal</th><th></th></tr></thead>
    <tbody>
    @foreach ($jenis as $j)
      @forelse ($tarif->get($j->id, collect()) as $t)
        <tr><td>{{ $j->nama }}<br><span class="hint">{{ $j->frekuensi->value ?? $j->frekuensi }}{{ $j->potong_otomatis ? ' · potong otomatis' : '' }}</span></td>
          <td>{{ $t->kelas?->nama ?? 'Semua kelas' }}</td><td class="num">{{ $t->berlaku_mulai->translatedFormat('d M Y') }}</td>
          <td class="r rp">{{ $rp($t->nominal) }}</td>
          <td class="r"><form method="post" action="{{ route('tarif.destroy', $t) }}" onsubmit="return confirm('Hapus tarif ini?')">@csrf @method('delete')<button class="btn sm">Hapus</button></form></td></tr>
      @empty
        <tr><td>{{ $j->nama }}</td><td colspan="4"><span class="chip c-warn">Belum diatur</span></td></tr>
      @endforelse
    @endforeach
    </tbody>
  </table></div></div>

  <form class="card stack" method="post" action="{{ route('tarif.store') }}">@csrf
    <h2>Tambah / ubah tarif</h2>
    <input type="hidden" name="tahun_ajaran_id" value="{{ $ta->id }}">
    <div class="field"><label for="j">Jenis</label><select id="j" name="jenis_tagihan_id" required>@foreach ($jenis as $j)<option value="{{ $j->id }}">{{ $j->nama }}</option>@endforeach</select></div>
    <div class="field"><label for="k">Kelas</label><select id="k" name="kelas_id"><option value="">Semua kelas</option>@foreach ($daftarKelas as $k)<option value="{{ $k->id }}">{{ $k->nama }}</option>@endforeach</select></div>
    <div class="field"><label for="b">Berlaku mulai</label><input id="b" name="berlaku_mulai" type="date" required value="{{ $ta->mulai->toDateString() }}" min="{{ $ta->mulai->toDateString() }}" max="{{ $ta->selesai->toDateString() }}"></div>
    <div class="field"><label for="n">Nominal (Rp)</label><input id="n" name="nominal" type="number" min="0" required></div>
    <div class="field"><label for="ket">Keterangan</label><input id="ket" name="keterangan" type="text" maxlength="200" placeholder="mis. SK Yayasan No. …"></div>
    <p class="hint">Tarif dengan jenis, kelas, dan tanggal berlaku yang sama akan diganti. Tarif per kelas mengalahkan tarif "semua kelas".</p>
    <div><button class="btn p">Simpan tarif</button></div>
  </form>
</div>
@endsection
