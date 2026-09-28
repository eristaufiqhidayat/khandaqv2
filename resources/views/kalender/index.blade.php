@extends('layouts.staf')
@section('judul', 'Kalender akademik')
@section('halaman')
<div class="top"><div><h1>Kalender akademik</h1><p class="muted">Kegiatan pondok (libur, ujian, pembagian raport, penjemputan). Tampil di portal wali, menu Kalender.</p></div>
  <form method="get" class="row"><select name="ta" onchange="this.form.submit()" aria-label="Tahun ajaran" style="width:auto">@foreach ($daftarTa as $t)<option value="{{ $t->id }}" @selected($t->id === $ta->id)>{{ $t->nama }}</option>@endforeach</select></form></div>
<div class="grid" style="grid-template-columns:minmax(0,1fr) 320px;align-items:start">
  <div class="card">
    @forelse ($kegiatan as $bulan => $daftar)
      <h2 style="margin:4px 0 6px">{{ \Carbon\CarbonImmutable::parse($bulan.'-01')->translatedFormat('F Y') }}</h2>
      @foreach ($daftar as $k)
        <div class="ph-line"><span><b>{{ $k->kegiatan }}</b><br><span class="hint">{{ $k->rentang() }}@if ($k->keterangan) · {{ $k->keterangan }}@endif</span></span>
          <span class="row" style="gap:6px;align-self:center"><a class="btn sm" href="{{ route('kalender.index', ['ta' => $ta->id, 'ubah' => $k->id]) }}">Ubah</a>
          <form method="post" action="{{ route('kalender.destroy', $k) }}" onsubmit="return confirm('Hapus kegiatan ini?')">@csrf @method('delete')<button class="btn sm d">Hapus</button></form></span></div>
      @endforeach
    @empty
      <p class="muted">Belum ada kegiatan di tahun ajaran {{ $ta->nama }}.</p>
    @endforelse
  </div>
  <form class="card stack" method="post" action="{{ $ubah ? route('kalender.update', $ubah) : route('kalender.store') }}">@csrf
    @if ($ubah) @method('put') <input type="hidden" name="ta" value="{{ $ta->id }}"> @endif
    <h2>{{ $ubah ? 'Ubah kegiatan' : 'Tambah kegiatan' }}</h2>
    <div class="field"><label for="kg">Kegiatan</label><input id="kg" name="kegiatan" type="text" required maxlength="200" value="{{ old('kegiatan', $ubah?->kegiatan) }}" placeholder="mis. Libur akhir semester"></div>
    <div class="field"><label for="tm">Tanggal mulai</label><input id="tm" name="tanggal_mulai" type="date" required value="{{ old('tanggal_mulai', $ubah?->tanggal_mulai?->toDateString()) }}"></div>
    <div class="field"><label for="ts">Tanggal selesai <span class="hint">(kosongkan bila satu hari)</span></label><input id="ts" name="tanggal_selesai" type="date" value="{{ old('tanggal_selesai', $ubah?->tanggal_selesai?->toDateString()) }}"></div>
    <div class="field"><label for="kt">Keterangan <span class="hint">(opsional)</span></label><textarea id="kt" name="keterangan" rows="2" maxlength="500">{{ old('keterangan', $ubah?->keterangan) }}</textarea></div>
    <div class="row"><button class="btn p">{{ $ubah ? 'Simpan' : 'Tambah' }}</button>@if ($ubah)<a class="btn" href="{{ route('kalender.index', ['ta' => $ta->id]) }}">Batal</a>@endif</div>
  </form>
</div>
@endsection
