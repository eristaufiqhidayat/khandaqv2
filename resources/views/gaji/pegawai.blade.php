@extends('layouts.staf')
@section('judul', 'Data pegawai')
@php($rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.'))
@section('halaman')
<div class="top"><div><h1>Data pegawai</h1><p class="muted">Guru & karyawan penerima gaji lewat payroll BSI. {{ $pegawai->where('aktif', true)->count() }} aktif, total nominal tetap {{ $rp($pegawai->where('aktif', true)->sum('nominal_tetap')) }}.</p></div>
  @include('gaji._tab', ['aktif' => 'pegawai'])</div>
@error('berkas')<div class="alert err" role="alert">{{ $message }}</div>@enderror
@if ($errors->any() && ! $errors->has('berkas'))<div class="alert err" role="alert">{{ $errors->first() }}</div>@endif

<div class="grid" style="grid-template-columns:minmax(0,1fr) 320px;align-items:start">
  <div class="stack">
    <div class="card"><div class="tw"><table><thead><tr><th>Nama</th><th>Rekening BSI</th><th>Notifikasi</th><th class="r">Nominal tetap</th><th></th></tr></thead><tbody>
    @forelse ($pegawai as $pg)
      <tr @unless ($pg->aktif) style="opacity:.6" @endunless><td>{{ $pg->nama }}@if ($pg->jabatan)<br><span class="hint">{{ $pg->jabatan }}</span>@endif @unless ($pg->aktif)<br><span class="chip">Nonaktif</span>@endunless</td>
        <td class="num">{{ $pg->no_rekening }}<br><span class="hint">a.n. {{ $pg->nama_rekening }}</span></td>
        <td><span class="hint">{{ $pg->email ?: '—' }}<br>{{ $pg->telepon ?: '—' }}</span></td>
        <td class="r rp">{{ $rp($pg->nominal_tetap) }}</td>
        <td class="r"><a class="btn sm" href="{{ route('gaji.pegawai.edit', $pg) }}">Ubah</a></td></tr>
    @empty
      <tr><td colspan="5" class="muted">Belum ada pegawai. Unggah berkas payroll BSI terakhir di samping untuk mengisi daftar sekaligus.</td></tr>
    @endforelse
    </tbody></table></div></div>
    @if ($nonaktif)<p class="hint">@if ($semua)<a href="{{ route('gaji.pegawai') }}">Sembunyikan {{ $nonaktif }} pegawai nonaktif</a>@else<a href="{{ route('gaji.pegawai', ['tampil' => 'semua']) }}">Tampilkan {{ $nonaktif }} pegawai nonaktif</a>@endif</p>@endif
  </div>

  <div class="stack">
    <form class="card stack" method="post" action="{{ route('gaji.pegawai.impor') }}" enctype="multipart/form-data">@csrf<h2>Isi dari berkas BSI</h2>
      <p class="hint" style="margin:0">Unggah berkas payroll .txt yang pernah dikirim ke BSI (mis. <code>gaji_01-09-2026.txt</code>). Pegawai dicocokkan lewat nomor rekening: yang baru ditambahkan, yang sudah ada diperbarui.</p>
      <div class="field"><label for="bk">Berkas .txt</label><input id="bk" name="berkas" type="file" accept=".txt,text/plain" required></div>
      <div><button class="btn">Unggah & isi data</button></div>
    </form>

    <form class="card stack" method="post" action="{{ route('gaji.pegawai.store') }}">@csrf<h2>Tambah pegawai</h2>
      @include('gaji._form-pegawai', ['pg' => null])
      <div><button class="btn p">Tambah</button></div>
    </form>
  </div>
</div>
@endsection
