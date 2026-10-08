@extends('layouts.staf')
@section('judul', 'Mapel & guru pengajar')
@section('halaman')
<div class="top"><div><h1>Mapel &amp; guru pengajar</h1><p class="muted">Mata pelajaran beserta KKM, dan kelas yang diajar tiap guru per tahun ajaran. Akun guru dibuat di menu Pengguna dengan peran <b>Guru</b>.</p></div>
  <form method="get" class="row"><label class="sr-only" for="m-ta">Tahun ajaran</label><select id="m-ta" name="ta" onchange="this.form.submit()" style="width:auto">@foreach ($daftarTa as $t)<option value="{{ $t->id }}" @selected($t->id === $ta->id)>{{ $t->nama }}</option>@endforeach</select></form></div>

<div class="grid g2" style="align-items:start">
  <div class="stack">
    <div class="card">
      <div class="card-h"><h2>Guru pengajar {{ $ta->nama }}</h2>
        @if ($taLalu && $penugasan->isEmpty())<form method="post" action="{{ route('mapel.salin', $ta) }}" onsubmit="return confirm('Salin semua penugasan dari {{ $taLalu->nama }}?')">@csrf<button class="btn sm">Salin dari {{ $taLalu->nama }}</button></form>@endif</div>
      @forelse ($penugasan as $daftar)
        <div class="ph-line" style="align-items:flex-start"><b style="min-width:140px">{{ $daftar->first()->guru->name }}</b>
          <div class="row" style="gap:6px;justify-content:flex-end">
            @foreach ($daftar as $g)
              <form method="post" action="{{ route('mapel.lepas', $g) }}" onsubmit="return confirm('Hapus penugasan {{ $g->kelas->nama }} · {{ $g->mapel->nama }}?')">@csrf @method('delete')
                <button class="chip c-acc" style="border:0;cursor:pointer" title="Klik untuk menghapus">{{ $g->kelas->nama }} · {{ $g->mapel->nama }} ✕</button></form>
            @endforeach</div></div>
      @empty
        <p class="muted">Belum ada penugasan di tahun ajaran {{ $ta->nama }}.</p>
      @endforelse
    </div>

    <form class="card stack" method="post" action="{{ route('mapel.tugaskan') }}">@csrf
      <input type="hidden" name="tahun_ajaran_id" value="{{ $ta->id }}">
      <h2>Tugaskan guru</h2>
      @if ($guru->isEmpty())
        <div class="note warn">Belum ada akun berperan Guru. Buat di menu Pengguna, pilih peran Guru.</div>
      @else
      <div class="grid g2">
        <div class="field"><label for="tg">Guru</label><select id="tg" name="user_id" required>@foreach ($guru as $u)<option value="{{ $u->id }}" @selected((int) old('user_id') === $u->id)>{{ $u->name }}</option>@endforeach</select></div>
        <div class="field"><label for="tm">Mata pelajaran</label><select id="tm" name="mapel_id" required>@foreach ($mapel->where('aktif', true) as $m)<option value="{{ $m->id }}" @selected((int) old('mapel_id') === $m->id)>{{ $m->nama }}</option>@endforeach</select></div>
      </div>
      <fieldset class="field"><legend>Kelas</legend>
        <div class="row" style="gap:6px 14px">@foreach ($kelas as $kl)<label class="cek"><input type="checkbox" name="kelas_id[]" value="{{ $kl->id }}" @checked(in_array($kl->id, old('kelas_id', [])))> {{ $kl->nama }}</label>@endforeach</div>
      </fieldset>
      <div><button class="btn p" @disabled($mapel->where('aktif', true)->isEmpty())>Tugaskan</button></div>
      @endif
    </form>
  </div>

  <div class="stack">
    <div class="card"><div class="card-h"><h2>Mata pelajaran</h2></div><div class="tw"><table>
      <thead><tr><th>Mapel</th><th>Kode</th><th class="r">KKM</th><th>Status</th><th></th></tr></thead><tbody>
      @forelse ($mapel as $m)
        <tr><td>{{ $m->nama }}</td><td class="num">{{ $m->kode ?? '–' }}</td><td class="r num">{{ $m->kkm }}</td>
          <td>@if ($m->aktif)<span class="chip c-good">Aktif</span>@else<span class="chip">Nonaktif</span>@endif</td>
          <td class="r"><div class="row" style="gap:6px;justify-content:flex-end"><a class="btn sm" href="{{ route('mapel.index', ['ta' => $ta->id, 'ubah' => $m->id]) }}">Ubah</a>
            <form method="post" action="{{ route('mapel.toggle', $m) }}">@csrf<button class="btn sm">{{ $m->aktif ? 'Nonaktifkan' : 'Aktifkan' }}</button></form></div></td></tr>
      @empty
        <tr><td colspan="5" class="muted">Belum ada mata pelajaran.</td></tr>
      @endforelse
    </tbody></table></div></div>

    <form class="card stack" method="post" action="{{ $ubah ? route('mapel.update', $ubah) : route('mapel.store') }}">@csrf
      @if ($ubah) @method('put') @endif
      <h2>{{ $ubah ? 'Ubah mapel' : 'Tambah mapel' }}</h2>
      <div class="field"><label for="mn">Nama</label><input id="mn" name="nama" type="text" required maxlength="100" value="{{ old('nama', $ubah?->nama) }}" placeholder="mis. Matematika"></div>
      <div class="grid g3">
        <div class="field"><label for="mk">Kode <span class="hint">(opsional)</span></label><input id="mk" name="kode" type="text" maxlength="20" value="{{ old('kode', $ubah?->kode) }}" placeholder="MTK"></div>
        <div class="field"><label for="mkkm">KKM</label><input id="mkkm" name="kkm" type="number" min="0" max="100" required value="{{ old('kkm', $ubah?->kkm ?? 75) }}"></div>
        <div class="field"><label for="mu">Urutan</label><input id="mu" name="urutan" type="number" min="0" max="999" value="{{ old('urutan', $ubah?->urutan ?? 0) }}"></div>
      </div>
      <div class="row"><button class="btn p">{{ $ubah ? 'Simpan' : 'Tambah mapel' }}</button>@if ($ubah)<a class="btn" href="{{ route('mapel.index', ['ta' => $ta->id]) }}">Batal</a>@endif</div>
    </form>
  </div>
</div>
@endsection
