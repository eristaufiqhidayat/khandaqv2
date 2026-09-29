@extends('layouts.wali')
@section('judul', 'Lapor transfer')
@section('judul-bar', 'Lapor Transfer')
@section('kembali', route('wali.tabungan'))
@section('wali')
@if ($errors->any())<div class="alert err" role="alert">{{ $errors->first() }}</div>@endif
<section class="card wb-kartu">
  <p class="hint" style="margin:0">Sudah transfer ke rekening pondok? Laporkan di sini dengan foto bukti transfer. Petugas mencocokkan dengan mutasi bank, lalu saldo tabungan bertambah.</p>
  @if ($bsi && $bsi->nomor)<p style="margin:8px 0 0"><b>{{ $bsi->bank ?? 'BSI' }} {{ $bsi->nomor }}</b><br><span class="hint">a.n. {{ $bsi->nama }}</span></p>@endif
</section>
@if ($anak->isEmpty())
  <div class="card"><p class="muted">Akun Anda belum ditautkan ke santri. Hubungi Admin Office.</p></div>
@else
<form class="card stack" method="post" action="{{ route('wali.lapor.store') }}" enctype="multipart/form-data">@csrf
  <div class="field"><label for="s">Santri</label>
    <select id="s" name="santri_id" required>@foreach ($anak as $s)<option value="{{ $s->id }}" @selected((int) old('santri_id', $pilih) === $s->id)>{{ $s->nama }} (kode {{ $s->kode_unik ?? '—' }})</option>@endforeach</select></div>
  <div class="field"><label for="n">Nominal transfer (Rp)</label><input id="n" name="nominal" type="number" inputmode="numeric" min="1000" required value="{{ old('nominal') }}" placeholder="mis. 1500242"></div>
  <div class="field"><label for="t">Tanggal transfer</label><input id="t" name="tanggal" type="date" required max="{{ now()->toDateString() }}" value="{{ old('tanggal', now()->toDateString()) }}"></div>
  <div class="field"><label for="b">Foto bukti transfer</label><input id="b" name="bukti" type="file" accept="image/*,application/pdf" required></div>
  <div class="field"><label for="c">Catatan <span class="hint">(opsional)</span></label><input id="c" name="catatan" type="text" maxlength="200" value="{{ old('catatan') }}" placeholder="mis. SPP Oktober + uang saku"></div>
  <div><button class="btn p">Kirim laporan</button></div>
</form>
@endif
@endsection
