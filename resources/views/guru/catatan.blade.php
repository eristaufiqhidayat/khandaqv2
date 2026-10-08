@extends('layouts.staf')
@section('judul', 'Catatan harian guru')
@section('halaman')
<div class="top"><div><h1>Catatan harian guru</h1><p class="muted">Jurnal mengajar: materi, kegiatan, santri tidak hadir, kendala, dan tindak lanjut. Hanya Anda yang melihat catatan Anda.</p></div>
  <form method="get" class="row">
    <label class="sr-only" for="c-bulan">Bulan</label><input id="c-bulan" type="month" name="bulan" value="{{ $bulan->format('Y-m') }}" onchange="this.form.submit()" style="width:auto">
    @if ($k->penugasan->isNotEmpty())<label class="sr-only" for="c-kelas">Kelas</label>
    <select id="c-kelas" name="kelas" onchange="this.form.submit()" style="width:auto"><option value="">Semua kelas</option>@foreach ($k->penugasan->unique('kelas_id') as $g)<option value="{{ $g->kelas_id }}" @selected($g->kelas_id === $filterKelas)>{{ $g->kelas->nama }}</option>@endforeach</select>@endif
  </form></div>

<div class="grid catatan-grid" style="align-items:start">
  <div class="card">
    <div class="card-h"><h2>{{ $bulan->translatedFormat('F Y') }}</h2><span class="muted">{{ $jumlah }} catatan</span></div>
    @forelse ($catatan as $tgl => $daftar)
      <h3 class="eyebrow" style="margin:12px 0 4px">{{ \Carbon\CarbonImmutable::parse($tgl)->translatedFormat('l, d F Y') }}</h3>
      @foreach ($daftar as $c)
        <div class="catatan-item">
          <div class="row" style="justify-content:space-between;align-items:flex-start">
            <div><b>{{ $c->materi }}</b><br><span class="hint">Kelas {{ $c->nama_kelas ?? '–' }}@if ($c->mapel) · {{ $c->mapel->nama }}@endif</span></div>
            <div class="row" style="gap:6px"><a class="btn sm" href="{{ route('guru.catatan.index', ['bulan' => $bulan->format('Y-m'), 'ubah' => $c->id]) }}">Ubah</a>
              <form method="post" action="{{ route('guru.catatan.destroy', $c) }}" onsubmit="return confirm('Hapus catatan ini?')">@csrf @method('delete')<button class="btn sm d">Hapus</button></form></div>
          </div>
          @if ($c->kegiatan)<p class="catatan-isi">{{ $c->kegiatan }}</p>@endif
          <div class="row" style="gap:6px;margin-top:6px">
            @if ($c->tidak_hadir)<span class="chip c-warn">Tidak hadir: {{ $c->tidak_hadir }}</span>@endif
            @if ($c->kendala)<span class="chip c-crit" title="{{ $c->kendala }}">Ada kendala</span>@endif
            @if ($c->tindak_lanjut)<span class="chip c-info" title="{{ $c->tindak_lanjut }}">Tindak lanjut</span>@endif
          </div>
          @if ($c->kendala || $c->tindak_lanjut)
          <details class="aksi" style="margin-top:6px"><summary class="hint">Kendala & tindak lanjut</summary>
            @if ($c->kendala)<p class="catatan-isi"><b>Kendala:</b> {{ $c->kendala }}</p>@endif
            @if ($c->tindak_lanjut)<p class="catatan-isi"><b>Tindak lanjut:</b> {{ $c->tindak_lanjut }}</p>@endif
          </details>
          @endif
        </div>
      @endforeach
    @empty
      <p class="muted">Belum ada catatan di bulan ini.</p>
    @endforelse
  </div>

  @if ($k->penugasan->isEmpty())
    @include('guru._tanpa-tugas')
  @else
  @php($pilihTugas = old('penugasan', $ubah ? $k->penugasan->first(fn ($g) => $g->kelas_id === $ubah->kelas_id && $g->mapel_id === $ubah->mapel_id)?->id : null))
  <form class="card stack" method="post" action="{{ $ubah ? route('guru.catatan.update', $ubah) : route('guru.catatan.store') }}">@csrf
    @if ($ubah) @method('put') @endif
    <h2>{{ $ubah ? 'Ubah catatan' : 'Tulis catatan' }}</h2>
    <div class="grid g2">
      <div class="field"><label for="ct">Tanggal</label><input id="ct" name="tanggal" type="date" required max="{{ now()->toDateString() }}" value="{{ old('tanggal', $ubah?->tanggal?->toDateString() ?? now()->toDateString()) }}"></div>
      <div class="field"><label for="cp">Kelas · mapel</label><select id="cp" name="penugasan" required>@foreach ($k->penugasan as $g)<option value="{{ $g->id }}" @selected((int) $pilihTugas === $g->id)>{{ $g->kelas->nama }} · {{ $g->mapel->nama }}</option>@endforeach</select></div>
    </div>
    <div class="field"><label for="cm">Materi / pokok bahasan</label><input id="cm" name="materi" type="text" required maxlength="200" value="{{ old('materi', $ubah?->materi) }}" placeholder="mis. Pecahan senilai"></div>
    <div class="field"><label for="ck">Kegiatan pembelajaran <span class="hint">(opsional)</span></label><textarea id="ck" name="kegiatan" rows="3" maxlength="5000" placeholder="Apa yang dilakukan di kelas">{{ old('kegiatan', $ubah?->kegiatan) }}</textarea></div>
    <div class="field"><label for="ch">Santri tidak hadir <span class="hint">(opsional)</span></label><input id="ch" name="tidak_hadir" type="text" maxlength="255" value="{{ old('tidak_hadir', $ubah?->tidak_hadir) }}" placeholder="mis. Ahmad (sakit), Rafi (izin)"></div>
    <div class="field"><label for="ckd">Kendala <span class="hint">(opsional)</span></label><textarea id="ckd" name="kendala" rows="2" maxlength="5000">{{ old('kendala', $ubah?->kendala) }}</textarea></div>
    <div class="field"><label for="ctl">Tindak lanjut <span class="hint">(opsional)</span></label><textarea id="ctl" name="tindak_lanjut" rows="2" maxlength="5000">{{ old('tindak_lanjut', $ubah?->tindak_lanjut) }}</textarea></div>
    <div class="row"><button class="btn p">{{ $ubah ? 'Simpan' : 'Simpan catatan' }}</button>@if ($ubah)<a class="btn" href="{{ route('guru.catatan.index', ['bulan' => $bulan->format('Y-m')]) }}">Batal</a>@endif</div>
  </form>
  @endif
</div>
@endsection
