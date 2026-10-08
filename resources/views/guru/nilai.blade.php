@extends('layouts.staf')
@section('judul', 'Input nilai siswa')
@section('halaman')
<div class="top"><div><h1>Input nilai siswa</h1><p class="muted">Kelola nilai Harian, UTS, dan UAS per kelas dan mata pelajaran.</p></div>
  @if ($k->pilih)<a class="btn" href="{{ route('guru.rekap.index', $k->query()) }}">Lihat rekap</a>@endif</div>
<div class="stack">
  @include('guru._filter', ['aksi' => route('guru.nilai.index'), 'sembunyi' => ['tab' => $tab]])

  @if (! $k->pilih)
    @include('guru._tanpa-tugas')
  @else
  @php($mapel = $k->pilih->mapel)
  @php($cfg = $tabs[$tab])
  <div class="seg" role="group" aria-label="Jenis nilai">
    @foreach ($tabs as $kode => $t)<a class="segbtn" href="{{ route('guru.nilai.index', $k->query(['tab' => $kode])) }}" aria-pressed="{{ $kode === $tab ? 'true' : 'false' }}">{{ $t['label'] }}</a>@endforeach
  </div>

  <form method="post" action="{{ route('guru.nilai.simpan') }}" class="card" id="formNilai">@csrf
    <input type="hidden" name="semester" value="{{ $k->semester->id }}"><input type="hidden" name="kelas" value="{{ $k->pilih->kelas_id }}">
    <input type="hidden" name="mapel" value="{{ $mapel->id }}"><input type="hidden" name="tab" value="{{ $tab }}">
    <div class="card-h">
      <div class="row"><span class="chip c-acc">Mode: {{ $cfg['label'] }}</span>
        <span class="muted">Kelas {{ $k->pilih->kelas->nama }} · {{ $mapel->nama }} · {{ $k->ta->nama }} {{ $k->semester->nomor === 1 ? 'Ganjil' : 'Genap' }}</span></div>
      <button class="btn p" @disabled($santri->isEmpty())>Simpan nilai</button>
    </div>

    @if ($santri->isEmpty())
      <p class="muted">Belum ada santri aktif di kelas {{ $k->pilih->kelas->nama }} pada tahun ajaran {{ $k->ta->nama }}.</p>
    @else
    <div class="tw"><table class="tabel-nilai" data-kkm="{{ $mapel->kkm }}" data-mode="{{ $tab }}">
      <thead><tr><th>No</th><th>NIS</th><th>Nama siswa</th>
        @if ($tab === 'harian')@foreach (\App\Models\Nilai::LABEL_HARIAN as $lbl)<th>{{ $lbl }}</th>@endforeach<th>Rata-rata</th>@else<th>Nilai {{ strtoupper($tab) }}</th>@endif
        <th>Catatan</th></tr></thead>
      <tbody>
      @foreach ($santri as $i => $s)
        @php($n = $nilai->get($s->id))
        <tr><td class="num">{{ $i + 1 }}</td><td class="num">{{ $s->nis }}</td>
          <td><b>{{ $s->nama }}</b><br><span class="hint">{{ ucfirst($s->status->value) }}</span></td>
          @foreach ($cfg['kolom'] as $kol)
            <td><input class="skor num" type="number" inputmode="numeric" min="0" max="100" step="1" name="nilai[{{ $s->id }}][{{ $kol }}]"
              value="{{ old("nilai.{$s->id}.{$kol}", $n?->{$kol}) }}" aria-label="{{ \App\Models\Nilai::LABEL_HARIAN[$kol] ?? strtoupper($kol) }} {{ $s->nama }}"></td>
          @endforeach
          @if ($tab === 'harian')<td><b class="num rata-baris">–</b></td>@endif
          <td><input type="text" class="catatan-nilai" name="nilai[{{ $s->id }}][catatan]" maxlength="255" placeholder="opsional" value="{{ old("nilai.{$s->id}.catatan", $n?->{$cfg['catatan']}) }}" aria-label="Catatan {{ $s->nama }}"></td>
        </tr>
      @endforeach
      </tbody>
    </table></div>

    <div class="grid g4 ringkas-nilai">
      <div class="kpi note"><span class="eyebrow">Jumlah siswa</span><div class="v" id="nJumlah">{{ $santri->count() }}</div></div>
      <div class="kpi note"><span class="eyebrow">Rata-rata</span><div class="v" id="nRata">–</div></div>
      <div class="kpi note"><span class="eyebrow">Nilai tertinggi</span><div class="v" id="nMax">–</div></div>
      <div class="kpi note"><span class="eyebrow">Di bawah KKM {{ $mapel->kkm }}</span><div class="v" id="nBawah">0</div></div>
    </div>
    <p class="hint" style="margin-top:10px">Kosongkan bila belum dinilai. Rata-rata harian dihitung dari kolom yang terisi. Kotak merah = di bawah KKM {{ $mapel->kkm }}.</p>
    <div class="row" style="justify-content:flex-end;margin-top:6px"><button class="btn p">Simpan nilai</button></div>
    @endif
  </form>
  @endif
</div>
<script>
(() => {
  const tabel = document.querySelector('.tabel-nilai');
  if (!tabel) return;
  const kkm = Number(tabel.dataset.kkm), harian = tabel.dataset.mode === 'harian';
  let berubah = false;
  const hitung = () => {
    const akhir = [];
    tabel.querySelectorAll('tbody tr').forEach(tr => {
      const isi = [...tr.querySelectorAll('.skor')].filter(i => i.value !== '').map(i => Number(i.value));
      tr.querySelectorAll('.skor').forEach(i => {
        const v = i.value === '' ? null : Number(i.value);
        i.classList.toggle('skor-rendah', v !== null && v < kkm);
        i.classList.toggle('skor-ok', v !== null && v >= kkm);
      });
      const v = isi.length ? isi.reduce((a, b) => a + b, 0) / isi.length : null;
      if (harian) tr.querySelector('.rata-baris').textContent = v === null ? '–' : v.toFixed(1).replace('.', ',');
      if (v !== null) akhir.push(v);
    });
    const f = x => x.toFixed(1).replace('.', ',');
    document.getElementById('nRata').textContent = akhir.length ? f(akhir.reduce((a, b) => a + b, 0) / akhir.length) : '–';
    document.getElementById('nMax').textContent = akhir.length ? f(Math.max(...akhir)) : '–';
    document.getElementById('nBawah').textContent = akhir.filter(x => x < kkm).length;
  };
  tabel.addEventListener('input', () => { berubah = true; hitung(); });
  // Enter pindah ke baris berikutnya pada kolom yang sama (cepat mengetik nilai satu kolom).
  tabel.addEventListener('keydown', e => {
    if (e.key !== 'Enter' || !e.target.classList.contains('skor')) return;
    e.preventDefault();
    const td = e.target.closest('td'), idx = [...td.parentNode.children].indexOf(td);
    td.parentNode.nextElementSibling?.children[idx]?.querySelector('input')?.focus();
  });
  document.getElementById('formNilai').addEventListener('submit', () => { berubah = false; });
  window.addEventListener('beforeunload', e => { if (berubah) { e.preventDefault(); e.returnValue = ''; } });
  hitung();
})();
</script>
@endsection
