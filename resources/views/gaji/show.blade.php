@extends('layouts.staf')
@section('judul', $p->judul)
@php($rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.'))
@php($total = $p->rincian->sum('nominal'))
@section('halaman')
<div class="top"><div><h1>{{ $p->judul }}</h1>
  <p class="muted">{{ $p->rincian->count() }} penerima · total <b>{{ $rp($total) }}</b> · transfer {{ $p->tanggal_transfer->translatedFormat('d F Y') }}
    @if ($p->final()) · <span class="chip c-good">Terkirim {{ $p->difinalkan_pada?->translatedFormat('d M Y H:i') }}</span>@else · <span class="chip c-warn">Draf</span>@endif</p></div>
  <div class="row">
    <a class="btn" href="{{ route('gaji.index') }}">Kembali</a>
    @if ($galat)<button class="btn p" disabled title="Perbaiki baris bertanda merah dulu">Unduh berkas BSI</button>
    @else<a class="btn p" href="{{ route('gaji.unduh', $p) }}">Unduh {{ $p->namaFile() }}</a>@endif
  </div></div>
@error('gaji')<div class="alert err" role="alert">{{ $message }}</div>@enderror
@if ($errors->any() && ! $errors->has('gaji'))<div class="alert err" role="alert">{{ $errors->first() }}</div>@endif
@if ($galat)<div class="note warn" style="margin-bottom:14px">{{ count($galat) }} baris perlu diperbaiki di <a href="{{ route('gaji.pegawai') }}">Data pegawai</a> sebelum berkas bisa diunduh.</div>@endif

@if ($p->final())
  <div class="card"><div class="tw"><table data-sortable><thead><tr><th>No</th>
    <th data-sort="text"><button type="button" class="th-sort">Penerima</button></th>
    <th class="num" data-sort="text"><button type="button" class="th-sort">Rekening</button></th>
    <th>Pesan</th>
    <th class="r" data-sort="num"><button type="button" class="th-sort">Nominal</button></th></tr></thead><tbody>
  @foreach ($p->rincian as $i => $r)
    <tr><td class="num">{{ $i + 1 }}</td><td><span class="sv">{{ $r->pegawai->nama }}</span><br><span class="hint">{{ $r->nama_rekening }}</span></td>
      <td class="num">{{ $r->no_rekening }}</td><td>{{ $r->pesan ?: $p->pesan }}</td><td class="r rp">{{ $rp($r->nominal) }}</td></tr>
  @endforeach
  </tbody><tfoot><tr><th colspan="4">Total</th><th class="r rp">{{ $rp($total) }}</th></tr></tfoot></table></div></div>
  <p class="hint">Penggajian ini sudah dikunci. Berkasnya tetap bisa diunduh ulang persis sama.</p>
@else
<form method="post" action="{{ route('gaji.update', $p) }}" class="stack">@csrf @method('put')
  <div class="card grid g3" style="align-items:end">
    <div class="field"><label for="jd">Judul</label><input id="jd" name="judul" type="text" required maxlength="100" value="{{ old('judul', $p->judul) }}"></div>
    <div class="field"><label for="tt">Tanggal transfer</label><input id="tt" name="tanggal_transfer" type="date" required value="{{ old('tanggal_transfer', $p->tanggal_transfer->toDateString()) }}"></div>
    <div class="field"><label for="ps">Pesan bawaan (maks. 65)</label><input id="ps" name="pesan" type="text" required maxlength="65" value="{{ old('pesan', $p->pesan) }}"></div>
  </div>

  <div class="card"><div class="tw"><table data-sortable><thead><tr><th>No</th>
    <th data-sort="text"><button type="button" class="th-sort">Penerima</button></th>
    <th data-sort="text"><button type="button" class="th-sort">Rekening BSI</button></th>
    <th class="r" style="min-width:130px" data-sort="num"><button type="button" class="th-sort">Nominal (Rp)</button></th>
    <th>Pesan khusus</th><th>Hapus</th></tr></thead><tbody>
  @foreach ($p->rincian as $i => $r)
    @php($g = $galat[$r->id] ?? [])
    <tr @if ($g) style="background:var(--crit-soft)" @endif>
      <td class="num">{{ $i + 1 }}</td>
      <td><a href="{{ route('gaji.pegawai.edit', $r->pegawai) }}">{{ $r->pegawai->nama }}</a>@if ($r->pegawai->jabatan)<br><span class="hint">{{ $r->pegawai->jabatan }}</span>@endif
        @foreach ($g as $e)<br><span style="color:var(--crit);font-size:12.5px">{{ $e }}</span>@endforeach</td>
      <td class="num">{{ $r->pegawai->no_rekening }}<br><span class="hint">{{ $r->pegawai->nama_rekening }}</span></td>
      <td><input name="rincian[{{ $r->id }}][nominal]" type="number" min="0" step="1000" required value="{{ old("rincian.{$r->id}.nominal", $r->nominal) }}" aria-label="Nominal {{ $r->pegawai->nama }}" class="num" style="text-align:right"></td>
      <td><input name="rincian[{{ $r->id }}][pesan]" type="text" maxlength="65" value="{{ old("rincian.{$r->id}.pesan", $r->pesan) }}" placeholder="{{ $p->pesan }}" aria-label="Pesan {{ $r->pegawai->nama }}"></td>
      <td><label class="cek"><input type="checkbox" name="rincian[{{ $r->id }}][hapus]" value="1"><span class="sr-only">Hapus {{ $r->pegawai->nama }}</span></label></td>
    </tr>
  @endforeach
  </tbody><tfoot><tr><th colspan="3">Total (sebelum disimpan ulang)</th><th class="r rp">{{ $rp($total) }}</th><th colspan="2"></th></tr></tfoot></table></div>
  @if ($bisaDitambah->isNotEmpty())
    <details style="margin-top:12px"><summary class="hint" style="cursor:pointer">Tambah pegawai aktif yang belum ada di daftar ({{ $bisaDitambah->count() }})</summary>
      <div class="row" style="flex-wrap:wrap;gap:6px 16px;margin-top:8px">@foreach ($bisaDitambah as $pg)<label class="cek"><input type="checkbox" name="tambah[]" value="{{ $pg->id }}"> {{ $pg->nama }}</label>@endforeach</div></details>
  @endif
  </div>
  <div class="row"><button class="btn p">Simpan</button><span class="hint">Simpan dulu setelah mengubah nominal, baru unduh berkas.</span></div>
</form>

<div class="card stack" style="margin-top:16px">
  <h2>Setelah berkas diunggah ke BSI</h2>
  <p class="hint" style="margin:0">Tandai terkirim agar nominal & data rekening dikunci. Transfer gaji di rekening koran akan tampil sebagai debet "Gaji ..." saat impor mutasi.</p>
  <div class="row">
    <form method="post" action="{{ route('gaji.final', $p) }}" onsubmit="return confirm('Tandai {{ $p->judul }} sudah dikirim ke BSI? Nominal tidak bisa diubah lagi.')">@csrf<button class="btn" @disabled($galat)>Tandai sudah dikirim ke BSI</button></form>
    <form method="post" action="{{ route('gaji.destroy', $p) }}" onsubmit="return confirm('Hapus draf {{ $p->judul }}?')">@csrf @method('delete')<button class="btn">Hapus draf</button></form>
  </div>
</div>
@endif
<script>
(() => {
  // Urutkan baris tabel gaji (tampilan terkunci maupun form entri draf) dengan klik judul kolom.
  document.querySelectorAll('table[data-sortable]').forEach((tabel) => {
    const ths = [...tabel.querySelectorAll('thead th[data-sort]')];
    const nilaiSel = (td, tipe) => {
      const input = td.querySelector('input[type="text"], input[type="number"]');
      const teks = (input ? input.value : (td.querySelector('.sv, a')?.textContent ?? td.textContent)).trim();
      return tipe === 'num' ? (parseInt(teks.replace(/\D/g, ''), 10) || 0) : teks.toLowerCase();
    };
    ths.forEach((th) => {
      const tombol = th.querySelector('.th-sort');
      tombol.addEventListener('click', () => {
        const kolom = [...th.parentElement.children].indexOf(th);
        const tipe = th.dataset.sort;
        const naik = th.getAttribute('aria-sort') !== 'ascending';
        ths.forEach((h) => h.removeAttribute('aria-sort'));
        th.setAttribute('aria-sort', naik ? 'ascending' : 'descending');
        const tbody = tabel.querySelector('tbody');
        const baris = [...tbody.querySelectorAll('tr')];
        baris.sort((a, b) => {
          const va = nilaiSel(a.children[kolom], tipe), vb = nilaiSel(b.children[kolom], tipe);
          const cmp = tipe === 'num' ? va - vb : va.localeCompare(vb, 'id');
          return naik ? cmp : -cmp;
        });
        baris.forEach((tr, i) => {
          tbody.appendChild(tr);
          tr.children[0].textContent = i + 1;
        });
      });
    });
  });
})();
</script>
@endsection
