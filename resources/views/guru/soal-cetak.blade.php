@extends('layouts.dasar')
@section('judul', $judul ?: 'Paket soal')
@section('isi')
<div class="cetak-soal">
  <div class="no-cetak row" style="justify-content:space-between;margin-bottom:16px">
    <span class="muted">{{ $soal->count() }} soal. Gunakan Cetak / Simpan sebagai PDF di browser.</span>
    <div class="row"><button class="btn p" type="button" onclick="window.print()">Cetak</button><button class="btn" type="button" onclick="window.close()">Tutup</button></div>
  </div>
  <header class="cetak-kop">
    <img src="{{ asset('img/logo.png') }}" alt="" width="56" height="56">
    <div><b>Pesantren Modern Lembah Arafah</b><br><span>{{ $judul ?: 'Soal Pilihan Ganda' }}</span><br>
      <span class="hint">@if ($mapelNama){{ $mapelNama }}@endif @if ($f['tingkat']) · Kelas {{ $f['tingkat'] }}@endif @if ($f['topik']) · {{ $f['topik'] }}@endif</span></div>
  </header>
  <table class="cetak-identitas"><tr><td>Nama</td><td>: ……………………………………</td><td>Kelas</td><td>: …………</td></tr><tr><td>Tanggal</td><td>: ……………………………………</td><td>Nilai</td><td>: …………</td></tr></table>
  <p class="hint" style="margin:10px 0">Pilihlah satu jawaban yang paling tepat dengan memberi tanda silang (X) pada huruf A, B, C, D{{ $soal->contains(fn ($s) => filled($s->opsi_e)) ? ', atau E' : '' }}.</p>
  <ol class="cetak-daftar">
    @foreach ($soal as $s)
      <li><p>{{ $s->pertanyaan }}</p><ol type="A">@foreach ($s->opsi() as $teks)<li>{{ $teks }}</li>@endforeach</ol></li>
    @endforeach
  </ol>
  @if ($kunci && $soal->isNotEmpty())
  <section class="cetak-kunci">
    <h2>Kunci jawaban</h2>
    <ol class="kunci-grid">@foreach ($soal as $s)<li><b>{{ strtoupper($s->jawaban) }}</b></li>@endforeach</ol>
  </section>
  @endif
</div>
@endsection
