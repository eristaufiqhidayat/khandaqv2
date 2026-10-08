{{-- Pilihan Tahun ajaran, Semester, Kelas, Mapel. Mapel disaring sesuai kelas (data-kelas). Variabel: $k (KonteksGuru), $aksi (url), $sembunyi (array) --}}
@php($kelasUnik = $k->penugasan->unique('kelas_id'))
<form method="get" action="{{ $aksi }}" class="card" id="filterGuru">
  @foreach ($sembunyi ?? [] as $n => $v)<input type="hidden" name="{{ $n }}" value="{{ $v }}">@endforeach
  <div class="form-baris">
    <div class="field"><label for="f-ta">Tahun ajaran</label>
      <select id="f-ta" name="ta" onchange="this.form.semester.value='';this.form.submit()">@foreach ($k->daftarTa as $t)<option value="{{ $t->id }}" @selected($t->id === $k->ta->id)>{{ $t->nama }}</option>@endforeach</select></div>
    <div class="field"><label for="f-sem">Semester</label>
      <select id="f-sem" name="semester">@foreach ($k->ta->semester as $s)<option value="{{ $s->id }}" @selected($s->id === $k->semester->id)>{{ $s->nomor === 1 ? 'Ganjil' : 'Genap' }}{{ $s->aktif ? ' (aktif)' : '' }}</option>@endforeach</select></div>
    @if ($k->penugasan->isNotEmpty())
    <div class="field"><label for="f-kelas">Kelas</label>
      <select id="f-kelas" name="kelas">@foreach ($kelasUnik as $g)<option value="{{ $g->kelas_id }}" @selected($g->kelas_id === $k->pilih?->kelas_id)>{{ $g->kelas->nama }}</option>@endforeach</select></div>
    @if ($pakaiMapel ?? true)
    <div class="field"><label for="f-mapel">Mata pelajaran</label>
      <select id="f-mapel" name="mapel">@foreach ($k->penugasan as $g)<option value="{{ $g->mapel_id }}" data-kelas="{{ $g->kelas_id }}" @selected($g->kunci === $k->pilih?->kunci)>{{ $g->mapel->nama }}</option>@endforeach</select></div>
    @endif
    @endif
    <div><button class="btn p">Tampilkan</button></div>
  </div>
</form>
<script>
(() => {
  const kelas = document.getElementById('f-kelas'), mapel = document.getElementById('f-mapel');
  if (!kelas || !mapel) return;
  const saring = () => {
    let pilih = null;
    for (const o of mapel.options) {
      const cocok = o.dataset.kelas === kelas.value;
      o.hidden = o.disabled = !cocok;
      if (cocok && (o.selected || !pilih)) pilih = o.selected ? o : (pilih || o);
    }
    if (mapel.selectedOptions[0]?.disabled && pilih) pilih.selected = true;
  };
  kelas.addEventListener('change', saring); saring();
})();
</script>
