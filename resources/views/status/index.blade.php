@extends('layouts.staf')
@section('judul', 'Status pembayaran')
@php
  $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
  $sel = ['lunas' => ['lunas', '✓', 'Lunas tepat waktu'], 'lunas_telat' => ['telat', '✓', 'Lunas, dibayar terlambat'], 'terlambat' => ['terlambat', '!', 'Menunggak (lewat jatuh tempo, belum lunas)'],
    'sebagian' => ['sebagian', '½', 'Dibayar sebagian'], 'belum' => ['belum', '', 'Belum jatuh tempo'], '-' => ['kosong', '', 'Belum terbit']];
  $tglId = fn (?string $d) => $d ? \Carbon\CarbonImmutable::parse($d)->translatedFormat('d M Y') : '—';
  $url = fn (array $ubah) => route('laporan.status', array_merge(request()->only(['ta', 'kelas', 'status', 'tab']), $ubah));
@endphp
@section('halaman')
<div class="top"><div><h1>Status pembayaran</h1><p class="muted">Tahun ajaran {{ $ta->nama }} · SPP lunas {{ $ringkasan['lunas'] }} dari {{ $ringkasan['berbayar'] }} santri berbayar ({{ $ringkasan['persen'] }}%)</p></div></div>

<form class="row" method="get" style="margin-bottom:14px">
  <div class="seg" role="group" aria-label="Jenis">
    <a href="{{ $url(['tab' => 'spp']) }}" class="segbtn" aria-pressed="{{ $tab === 'spp' ? 'true' : 'false' }}">SPP bulanan</a>
    <a href="{{ $url(['tab' => 'dsb']) }}" class="segbtn" aria-pressed="{{ $tab === 'dsb' ? 'true' : 'false' }}">DSB & Daftar Ulang</a>
  </div>
  <input type="hidden" name="tab" value="{{ $tab }}">
  @if ($tab === 'spp')
    <select name="ta" aria-label="Tahun ajaran" style="width:auto" onchange="this.form.submit()">@foreach ($daftarTa as $t)<option value="{{ $t->id }}" @selected($t->id === $ta->id)>{{ $t->nama }}</option>@endforeach</select>
    <select name="kelas" aria-label="Kelas" style="width:auto" onchange="this.form.submit()"><option value="">Semua kelas</option>@foreach ($daftarKelas as $k)<option value="{{ $k->id }}" @selected($kelas?->id === $k->id)>{{ $k->nama }}</option>@endforeach</select>
    <select name="status" aria-label="Status" style="width:auto" onchange="this.form.submit()">
      <option value="semua">Semua santri</option><option value="menunggak" @selected($status === 'menunggak')>Menunggak</option><option value="lunas" @selected($status === 'lunas')>Lancar</option>
    </select>
    <noscript><button class="btn">Tampilkan</button></noscript>
  @endif
</form>

@if ($tab === 'spp')
  @php($menunggak = collect($spp)->where('bulan_terlambat', '>', 0))
  <div class="card">
    <div class="card-h">
      <div class="row"><span class="chip {{ $menunggak->count() ? 'c-crit' : 'c-good' }}">{{ $menunggak->count() }} santri menunggak</span>
      <span class="hint">Total tunggakan {{ $rp($menunggak->sum('sisa_terlambat')) }} · {{ count($spp) }} santri ditampilkan</span></div>
      <div class="legend">@foreach (['lunas' => 'Lunas tepat waktu', 'telat' => 'Lunas terlambat', 'terlambat' => 'Menunggak', 'sebagian' => 'Sebagian', 'belum' => 'Belum jatuh tempo', 'kosong' => 'Belum terbit'] as $c => $l)<span><span class="bl {{ $c }} mini"></span>{{ $l }}</span>@endforeach</div>
    </div>
    <div class="tw"><table>
      <thead><tr><th>Santri</th><th>Kelas</th>
        <th style="text-transform:none;letter-spacing:0"><div class="grid12">@foreach ($bulan as $b)<span class="blh">{{ $b->translatedFormat('M') }}</span>@endforeach</div></th>
        <th class="r">Menunggak</th><th class="r">Telat bayar</th><th class="r">Tunggakan</th></tr></thead>
      <tbody>
      @forelse ($spp as $r)
        <tr>
          <td>{{ $r['santri']->nama }}<br><span class="hint">{{ $r['santri']->nis }}</span></td>
          <td>{{ $r['kelas'] ?? '—' }}</td>
          <td><div class="grid12">@foreach ($r['bulan'] as $tgl => $st)@php([$cls, $ikon, $arti] = $sel[$st])@php($d = $r['detail'][$tgl] ?? null)@php($judulBln = \Carbon\CarbonImmutable::parse($tgl)->translatedFormat('F Y'))
            @if ($d)<button type="button" class="bl {{ $cls }}" aria-label="{{ $judulBln }}: {{ $arti }}" data-info="{{ json_encode([
              'judul' => $r['santri']->nama.' · '.$judulBln, 'status' => $arti, 'kelas' => $cls, 'ket' => $d['keterangan'],
              'rincian' => array_values(array_filter([
                ['Tagihan', $rp($d['netto'] + $d['potongan'])],
                $d['potongan'] ? ['Potongan/beasiswa', '−'.$rp($d['potongan'])] : null,
                ['Terbayar', $rp($d['terbayar'])],
                $d['sisa'] ? ['Sisa', $rp($d['sisa'])] : null,
                ['Jatuh tempo', $tglId($d['jatuh_tempo'])],
                $d['tanggal_lunas'] ? ['Tanggal lunas', $tglId($d['tanggal_lunas']).($d['hari_telat'] ? ' (telat '.$d['hari_telat'].' hari)' : '')] : null,
              ])),
              'bayar' => array_map(fn ($b) => [$tglId($b['tanggal']), $rp($b['nominal'])], $d['bayar']),
            ]) }}">{{ $ikon }}</button>
            @else<span class="bl {{ $cls }}" title="{{ $judulBln }}: {{ $arti }}">{{ $ikon }}</span>@endif
          @endforeach</div></td>
          <td class="r num" style="{{ $r['bulan_terlambat'] ? 'color:var(--crit);font-weight:700' : '' }}">{{ $r['bulan_terlambat'] ?: '—' }}</td>
          <td class="r num" style="{{ $r['bulan_telat_bayar'] ? 'color:var(--warn);font-weight:700' : '' }}">{{ $r['bulan_telat_bayar'] ?: '—' }}</td>
          <td class="r rp">{{ $r['sisa_terlambat'] ? $rp($r['sisa_terlambat']) : '—' }}</td>
        </tr>
      @empty
        <tr><td colspan="6" class="muted">Belum ada santri aktif untuk filter ini. Bila aplikasi baru dipasang, jalankan dulu Sinkronisasi data lama.</td></tr>
      @endforelse
      </tbody>
    </table></div>
  </div>
  <div id="blpop" class="blpop" role="dialog" aria-modal="false" hidden>
    <button type="button" class="blpop-x" aria-label="Tutup">×</button>
    <b class="blpop-judul"></b><div class="blpop-status"></div><div class="hint blpop-ket"></div>
    <table class="blpop-rinci"><tbody></tbody></table>
    <div class="blpop-bayar"></div>
  </div>
  <script>
  (function () {
    var pop = document.getElementById('blpop'), aktif = null;
    function isi(sel, teks) { pop.querySelector(sel).textContent = teks || ''; }
    function tutup() { pop.hidden = true; if (aktif) { aktif.focus(); aktif = null; } }
    document.addEventListener('click', function (e) {
      var b = e.target.closest('button.bl[data-info]');
      if (!b) { if (!pop.hidden && !pop.contains(e.target)) tutup(); return; }
      var d = JSON.parse(b.dataset.info);
      isi('.blpop-judul', d.judul); isi('.blpop-ket', d.ket);
      var st = pop.querySelector('.blpop-status'); st.textContent = d.status; st.className = 'blpop-status chip ' + ({lunas: 'c-good', telat: 'c-gold', terlambat: 'c-crit', sebagian: 'c-warn'}[d.kelas] || '');
      var tb = pop.querySelector('tbody'); tb.innerHTML = '';
      d.rincian.forEach(function (r) { var tr = tb.insertRow(); tr.insertCell().textContent = r[0]; var c = tr.insertCell(); c.textContent = r[1]; c.className = 'r rp'; });
      var by = pop.querySelector('.blpop-bayar');
      by.textContent = d.bayar.length ? '' : 'Belum ada pembayaran.';
      if (d.bayar.length) { var h = document.createElement('div'); h.className = 'eyebrow'; h.textContent = 'Riwayat pembayaran'; by.appendChild(h);
        d.bayar.forEach(function (p) { var l = document.createElement('div'); l.className = 'ph-line'; l.innerHTML = '<span></span><span class="rp"></span>'; l.children[0].textContent = p[0]; l.children[1].textContent = p[1]; by.appendChild(l); }); }
      pop.hidden = false; aktif = b;
      var r = b.getBoundingClientRect(), w = pop.offsetWidth, hgt = pop.offsetHeight;
      var x = Math.min(Math.max(8, r.left + r.width / 2 - w / 2), window.innerWidth - w - 8);
      var y = r.bottom + 8 + hgt > window.innerHeight ? Math.max(8, r.top - hgt - 8) : r.bottom + 8;
      pop.style.left = x + 'px'; pop.style.top = y + 'px';
      pop.querySelector('.blpop-x').focus();
    });
    pop.querySelector('.blpop-x').addEventListener('click', tutup);
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !pop.hidden) tutup(); });
    window.addEventListener('scroll', function () { if (!pop.hidden) tutup(); }, true);
  })();
  </script>
@else
  <div class="card">
    <div class="card-h"><div class="row"><span class="chip c-warn">{{ $dsbdu->count() }} tagihan belum lunas</span><span class="hint">Total sisa {{ $rp($dsbdu->sum('sisa')) }} · semua tahun ajaran</span></div></div>
    <div class="tw"><table>
      <thead><tr><th>Santri</th><th>Jenis</th><th class="r">Nominal</th><th class="r">Diskon</th><th class="r">Terbayar</th><th class="r">Sisa</th><th>Jatuh tempo</th></tr></thead>
      <tbody>
      @forelse ($dsbdu as $r)
        <tr>
          <td>{{ $r['santri']->nama }}</td><td><span class="chip c-acc">{{ $r['jenis'] }}</span></td>
          <td class="r rp">{{ $rp($r['nominal']) }}</td><td class="r rp">{{ $r['diskon'] ? $rp($r['diskon']) : '—' }}</td>
          <td class="r rp">{{ $rp($r['terbayar']) }}</td><td class="r rp" style="font-weight:700">{{ $rp($r['sisa']) }}</td>
          <td class="num" style="{{ $r['jatuh_tempo'] < now()->toDateString() ? 'color:var(--crit)' : '' }}">{{ \Carbon\CarbonImmutable::parse($r['jatuh_tempo'])->translatedFormat('d M Y') }}</td>
        </tr>
      @empty
        <tr><td colspan="7" class="muted">Tidak ada DSB atau Daftar Ulang yang belum lunas.</td></tr>
      @endforelse
      </tbody>
    </table></div>
  </div>
@endif
@endsection
