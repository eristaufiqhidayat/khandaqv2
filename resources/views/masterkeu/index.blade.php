@extends('layouts.staf')
@section('judul', 'Rekening & akun biaya')
@section('halaman')
<div class="top"><div><h1>Rekening & akun biaya</h1><p class="muted">Dipakai saat mencatat setoran, pengeluaran, dan impor mutasi bank.</p></div></div>
@if ($errors->any())<div class="alert err" role="alert">{{ $errors->first() }}</div>@endif
<div class="grid g2" style="align-items:start">
  <div class="stack">
    <div class="card"><div class="card-h"><h2>Rekening</h2></div><div class="tw"><table><thead><tr><th>Kode</th><th>Nama & nomor</th><th>Jenis</th><th></th></tr></thead><tbody>
    @foreach ($rekening as $r)
      <tr><td class="num">{{ $r->kode }}</td><td colspan="3">
        <form method="post" action="{{ route('masterkeu.update', ['rekening', $r->id]) }}" class="form-baris">@csrf @method('put')
          <div class="field"><label for="rn{{ $r->id }}">Nama</label><input id="rn{{ $r->id }}" name="nama" type="text" value="{{ $r->nama }}" required></div>
          <div class="field"><label for="ro{{ $r->id }}">Nomor</label><input id="ro{{ $r->id }}" name="nomor" type="text" value="{{ $r->nomor }}"></div>
          <label class="cek"><input type="checkbox" name="aktif" value="1" @checked($r->aktif)> Aktif</label><button class="btn sm">Simpan</button></form></td></tr>
    @endforeach
    </tbody></table></div>
      <form method="post" action="{{ route('masterkeu.store', 'rekening') }}" class="form-baris" style="margin-top:10px">@csrf
        <div class="field"><label for="rk">Kode</label><input id="rk" name="kode" type="text" required maxlength="20"></div>
        <div class="field"><label for="rnn">Nama</label><input id="rnn" name="nama" type="text" required maxlength="100"></div>
        <div class="field"><label for="rj">Jenis</label><select id="rj" name="jenis"><option value="bank">Bank</option><option value="kas">Kas</option></select></div>
        <button class="btn sm">Tambah</button></form></div>

    <div class="card"><div class="card-h"><h2>Dana & jenis tagihan</h2><span class="hint">hanya lihat</span></div><div class="tw"><table><thead><tr><th>Jenis tagihan</th><th>Dana</th><th>Aturan</th></tr></thead><tbody>
    @foreach ($jenis as $j)<tr><td>{{ $j->nama }}</td><td>{{ $j->dana?->nama }}</td><td class="hint">{{ collect([$j->potong_otomatis ? 'potong otomatis' : null, $j->wajib_lunas_untuk_raport ? 'syarat raport' : null, $j->boleh_dicicil ? 'boleh dicicil' : 'tidak dicicil', $j->dihitung_tunggakan ? 'dihitung tunggakan' : null])->filter()->join(' · ') }}</td></tr>@endforeach
    </tbody></table></div></div>
  </div>
  <div class="stack">
    @foreach (['akun' => ['Akun biaya', $akun], 'pengusul' => ['Pengusul', $pengusul]] as $jenisMaster => [$judul, $baris])
    <div class="card"><div class="card-h"><h2>{{ $judul }}</h2><span class="chip">{{ $baris->count() }}</span></div><div class="tw"><table><tbody>
      @foreach ($baris as $b)
        <tr><td class="num">{{ $b->kode }}</td><td><form method="post" action="{{ route('masterkeu.update', [$jenisMaster, $b->id]) }}" class="form-baris">@csrf @method('put')
          <div class="field"><label class="sr-only" for="{{ $jenisMaster }}{{ $b->id }}">Nama</label><input id="{{ $jenisMaster }}{{ $b->id }}" name="nama" type="text" value="{{ $b->nama }}" required></div><button class="btn sm">Simpan</button></form></td></tr>
      @endforeach
      </tbody></table></div>
      <form method="post" action="{{ route('masterkeu.store', $jenisMaster) }}" class="form-baris" style="margin-top:10px">@csrf
        <div class="field"><label for="k{{ $jenisMaster }}">Kode</label><input id="k{{ $jenisMaster }}" name="kode" type="text" required maxlength="20"></div>
        <div class="field"><label for="n{{ $jenisMaster }}">Nama</label><input id="n{{ $jenisMaster }}" name="nama" type="text" required maxlength="100"></div>
        <button class="btn sm">Tambah</button></form></div>
    @endforeach
  </div>
</div>
@endsection
