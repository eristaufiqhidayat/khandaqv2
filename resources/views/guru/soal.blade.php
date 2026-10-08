@extends('layouts.staf')
@section('judul', 'Soal pilihan ganda')
@section('halaman')
<div class="top"><div><h1>Soal pilihan ganda</h1><p class="muted">Bank soal bersama untuk mapel yang Anda ajar. Soal guru lain bisa dipakai & dicetak; hanya pembuatnya yang bisa mengubah.</p></div></div>

@if ($mapel->isEmpty())
  <div class="note warn" role="status">Belum ada mata pelajaran yang ditugaskan kepada Anda. Minta Admin menambahkannya di menu <b>Mapel &amp; guru pengajar</b>.</div>
@else
<div class="grid soal-grid" style="align-items:start">
  <div class="stack">
    <form method="get" class="card">
      <div class="form-baris">
        <div class="field"><label for="s-mapel">Mapel</label><select id="s-mapel" name="mapel"><option value="">Semua</option>@foreach ($mapel as $m)<option value="{{ $m->id }}" @selected($f['mapel'] === $m->id)>{{ $m->nama }}</option>@endforeach</select></div>
        <div class="field"><label for="s-tingkat">Tingkat</label><select id="s-tingkat" name="tingkat"><option value="">Semua</option>@foreach (range(1, 6) as $t)<option value="{{ $t }}" @selected($f['tingkat'] === $t)>Kelas {{ $t }}</option>@endforeach</select></div>
        <div class="field"><label for="s-topik">Topik</label><select id="s-topik" name="topik"><option value="">Semua</option>@foreach ($topik as $t)<option @selected($f['topik'] === $t)>{{ $t }}</option>@endforeach</select></div>
        <div class="field"><label for="s-kes">Kesulitan</label><select id="s-kes" name="kesulitan"><option value="">Semua</option>@foreach ($kesulitan as $kode => $lbl)<option value="{{ $kode }}" @selected($f['kesulitan'] === $kode)>{{ $lbl }}</option>@endforeach</select></div>
        <div class="field" style="flex-basis:180px"><label for="s-q">Cari pertanyaan</label><input id="s-q" type="search" name="q" value="{{ $f['q'] }}"></div>
      </div>
      <div class="row" style="margin-top:10px;justify-content:space-between">
        <label class="cek"><input type="checkbox" name="saya" value="1" @checked($f['saya'])> Hanya soal buatan saya</label>
        <button class="btn p">Saring</button>
      </div>
    </form>

    <div class="card">
      <div class="card-h"><h2>{{ $soal->total() }} soal</h2>
        @if ($soal->total())
        <details class="aksi"><summary class="btn sm">Cetak paket soal</summary>
          <form method="get" action="{{ route('guru.soal.cetak') }}" target="_blank" class="stack" style="margin-top:8px;min-width:260px">
            @foreach (array_filter($f) as $n => $v)<input type="hidden" name="{{ $n }}" value="{{ $v }}">@endforeach
            <div class="field"><label for="p-judul">Judul</label><input id="p-judul" type="text" name="judul" maxlength="120" placeholder="mis. Ulangan Harian 1"></div>
            <label class="cek"><input type="checkbox" name="acak" value="1"> Acak urutan soal</label>
            <label class="cek"><input type="checkbox" name="kunci" value="1"> Sertakan kunci jawaban</label>
            <p class="hint">Mencetak soal sesuai saringan (maks. 200).</p>
            <button class="btn p">Buka lembar cetak</button>
          </form></details>
        @endif
      </div>
      @forelse ($soal as $i => $s)
        <article class="soal-item">
          <div class="row" style="justify-content:space-between;align-items:flex-start">
            <div class="row" style="gap:6px"><span class="num faint">{{ $soal->firstItem() + $i }}.</span>
              <span class="chip c-acc">{{ $s->mapel->nama }}</span><span class="chip">Kelas {{ $s->tingkat }}</span>
              <span class="chip {{ ['mudah' => 'c-good', 'sedang' => 'c-gold', 'sulit' => 'c-crit'][$s->kesulitan] ?? '' }}">{{ $kesulitan[$s->kesulitan] ?? $s->kesulitan }}</span>
              @if ($s->topik)<span class="hint">{{ $s->topik }}</span>@endif</div>
            @if ($s->dibuat_oleh === auth()->id())
            <div class="row" style="gap:6px"><a class="btn sm" href="{{ request()->fullUrlWithQuery(['ubah' => $s->id]) }}#form-soal">Ubah</a>
              <form method="post" action="{{ route('guru.soal.destroy', $s) }}" onsubmit="return confirm('Hapus soal ini?')">@csrf @method('delete')<button class="btn sm d">Hapus</button></form></div>
            @else<span class="hint">oleh {{ $s->pembuat?->name ?? '–' }}</span>@endif
          </div>
          <p class="soal-tanya">{{ $s->pertanyaan }}</p>
          <ol class="soal-opsi" type="A">
            @foreach ($s->opsi() as $h => $teks)<li @if ($h === $s->jawaban) class="benar" @endif>{{ $teks }}@if ($h === $s->jawaban) <span class="sr-only">(jawaban)</span>@endif</li>@endforeach
          </ol>
          @if ($s->pembahasan)<details class="aksi"><summary class="hint">Pembahasan</summary><p class="catatan-isi">{{ $s->pembahasan }}</p></details>@endif
        </article>
      @empty
        <p class="muted">Belum ada soal yang cocok. Tambahkan soal pertama lewat formulir di samping.</p>
      @endforelse
      {{ $soal->links() }}
    </div>
  </div>

  <form class="card stack" id="form-soal" method="post" action="{{ $ubah ? route('guru.soal.update', $ubah) : route('guru.soal.store') }}">@csrf
    @if ($ubah) @method('put') @endif
    <h2>{{ $ubah ? 'Ubah soal' : 'Tambah soal' }}</h2>
    <div class="grid g2">
      <div class="field"><label for="fm">Mapel</label><select id="fm" name="mapel_id" required>@foreach ($mapel as $m)<option value="{{ $m->id }}" @selected((int) old('mapel_id', $ubah?->mapel_id ?? $f['mapel']) === $m->id)>{{ $m->nama }}</option>@endforeach</select></div>
      <div class="field"><label for="ft">Tingkat kelas</label><select id="ft" name="tingkat" required>@foreach (range(1, 6) as $t)<option value="{{ $t }}" @selected((int) old('tingkat', $ubah?->tingkat ?? $f['tingkat'] ?? $tingkat->first()) === $t)>Kelas {{ $t }}</option>@endforeach</select></div>
    </div>
    <div class="grid g2">
      <div class="field"><label for="ftp">Topik <span class="hint">(opsional)</span></label><input id="ftp" name="topik" type="text" maxlength="100" list="daftar-topik" value="{{ old('topik', $ubah?->topik) }}"><datalist id="daftar-topik">@foreach ($topik as $t)<option value="{{ $t }}">@endforeach</datalist></div>
      <div class="field"><label for="fk">Kesulitan</label><select id="fk" name="kesulitan">@foreach ($kesulitan as $kode => $lbl)<option value="{{ $kode }}" @selected(old('kesulitan', $ubah?->kesulitan ?? 'sedang') === $kode)>{{ $lbl }}</option>@endforeach</select></div>
    </div>
    <div class="field"><label for="fp">Pertanyaan</label><textarea id="fp" name="pertanyaan" rows="3" required maxlength="5000">{{ old('pertanyaan', $ubah?->pertanyaan) }}</textarea></div>
    <fieldset class="field"><legend>Pilihan jawaban <span class="hint">(pilih bulatan untuk jawaban benar; E opsional)</span></legend>
      @foreach (\App\Models\SoalPg::OPSI as $h)
        <div class="opsi-baris"><input type="radio" name="jawaban" value="{{ $h }}" id="j{{ $h }}" @checked(old('jawaban', $ubah?->jawaban ?? 'a') === $h) required>
          <label for="j{{ $h }}" class="num"><b>{{ strtoupper($h) }}</b></label>
          <input type="text" name="opsi_{{ $h }}" aria-label="Opsi {{ strtoupper($h) }}" maxlength="1000" @if ($h !== 'e') required @endif value="{{ old('opsi_'.$h, $ubah?->{'opsi_'.$h}) }}"></div>
      @endforeach
    </fieldset>
    <div class="field"><label for="fb">Pembahasan <span class="hint">(opsional)</span></label><textarea id="fb" name="pembahasan" rows="2" maxlength="5000">{{ old('pembahasan', $ubah?->pembahasan) }}</textarea></div>
    <div class="row"><button class="btn p">{{ $ubah ? 'Simpan' : 'Tambah soal' }}</button>@if ($ubah)<a class="btn" href="{{ request()->fullUrlWithoutQuery(['ubah']) }}">Batal</a>@endif</div>
  </form>
</div>
@endif
@endsection
