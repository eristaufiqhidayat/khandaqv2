@extends('layouts.staf')
@section('judul', 'Pengeluaran')
@php($rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.'))
@section('halaman')
<div class="top"><div><h1>Pengeluaran {{ $bulan->translatedFormat('F Y') }}</h1><p class="muted">Total {{ $rp($data->sum('nominal')) }} dari {{ $data->count() }} transaksi.</p></div>
  <form method="get" class="row"><input type="month" name="bulan" value="{{ $bulan->format('Y-m') }}" aria-label="Bulan" style="width:auto">
    <select name="dana" style="width:auto" aria-label="Dana"><option value="">Semua dana</option>@foreach ($dana as $d)<option value="{{ $d->id }}" @selected($danaId === $d->id)>{{ $d->nama }}</option>@endforeach</select><button class="btn">Tampilkan</button></form></div>
@error('pengeluaran')<div class="alert err" role="alert">{{ $message }}</div>@enderror
<div class="grid" style="grid-template-columns:minmax(0,1fr) 320px;align-items:start">
  <div class="stack">
    @if ($perDana->isNotEmpty())<div class="row">@foreach ($perDana as $nama => $n)<span class="chip c-acc">{{ $nama }} {{ $rp($n) }}</span>@endforeach</div>@endif
    <div class="card"><div class="tw"><table><thead><tr><th>Tanggal</th><th>Keterangan</th><th>Dana</th><th>Dari</th><th class="r">Nominal</th><th></th></tr></thead><tbody>
    @forelse ($data as $p)
      <tr><td class="num">{{ $p->tanggal->translatedFormat('d M') }}</td><td>{{ $p->keterangan }}<br><span class="hint">{{ $p->akun?->nama }}{{ $p->rutin ? ' · rutin' : '' }}</span>@if ($p->bukti_path) · <a href="{{ route('pengeluaran.bukti', $p) }}" target="_blank" rel="noopener" class="hint">bukti</a>@endif</td>
        <td>{{ $p->dana->nama }}</td><td>{{ $p->rekening->kode }}</td><td class="r rp">{{ $rp($p->nominal) }}</td>
        <td class="r"><form method="post" action="{{ route('pengeluaran.destroy', $p) }}" onsubmit="return confirm('Hapus pengeluaran ini?')">@csrf @method('delete')<button class="btn sm">Hapus</button></form></td></tr>
    @empty<tr><td colspan="6" class="muted">Belum ada pengeluaran bulan ini.</td></tr>@endforelse
    </tbody></table></div></div>
  </div>
  <form class="card stack" method="post" action="{{ route('pengeluaran.store') }}" enctype="multipart/form-data">@csrf<h2>Catat pengeluaran</h2>
    <div class="field"><label for="tg">Tanggal</label><input id="tg" name="tanggal" type="date" required max="{{ now()->toDateString() }}" value="{{ old('tanggal', now()->toDateString()) }}"></div>
    <div class="field"><label for="dn">Dari dana</label><select id="dn" name="dana_id" required>@foreach ($dana as $d)<option value="{{ $d->id }}" @selected((int) old('dana_id') === $d->id)>{{ $d->nama }}</option>@endforeach</select></div>
    <div class="field"><label for="rk">Dibayar dari</label><select id="rk" name="rekening_id" required>@foreach ($rekening as $r)<option value="{{ $r->id }}">{{ $r->kode }} · {{ $r->nama }}</option>@endforeach</select></div>
    <div class="grid g2"><div class="field"><label for="ak">Akun biaya</label><select id="ak" name="akun_id"><option value="">—</option>@foreach ($akun as $a)<option value="{{ $a->id }}">{{ $a->nama }}</option>@endforeach</select></div>
      <div class="field"><label for="pu">Pengusul</label><select id="pu" name="pengusul_id"><option value="">—</option>@foreach ($pengusul as $p)<option value="{{ $p->id }}">{{ $p->nama }}</option>@endforeach</select></div></div>
    <div class="field"><label for="nm">Nominal (Rp)</label><input id="nm" name="nominal" type="number" min="1" required value="{{ old('nominal') }}"></div>
    <div class="field"><label for="kt">Keterangan</label><input id="kt" name="keterangan" type="text" required maxlength="255" value="{{ old('keterangan') }}"></div>
    <div class="field"><label for="bk">Bukti (opsional)</label><input id="bk" name="bukti" type="file" accept=".jpg,.jpeg,.png,.pdf"></div>
    <label class="cek"><input type="checkbox" name="rutin" value="1"> Pengeluaran rutin</label>
    <div><button class="btn p">Simpan</button></div>
  </form>
</div>
@endsection
