@extends('layouts.staf')
@section('judul', 'Pendaftaran santri baru')
@php($rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.'))
@section('halaman')
<div class="top"><div><h1>Pendaftaran santri baru</h1><p class="muted">Untuk tahun ajaran {{ $taTujuan->nama }}. Alur: baru → formulir lunas → diterima (santri calon, akun wali, tagihan DSB).</p></div></div>
@error('pendaftaran')<div class="alert err" role="alert">{{ $message }}</div>@enderror

<div class="seg" role="group" aria-label="Status" style="margin-bottom:14px">
  @foreach (['proses' => 'Perlu diproses', 'diterima' => 'Diterima', 'ditolak' => 'Ditolak'] as $k => $l)
    <a class="segbtn" href="{{ route('pendaftaran.index', $k === 'proses' ? [] : ['status' => $k]) }}" aria-pressed="{{ $tab === $k ? 'true' : 'false' }}">{{ $l }}
      ({{ $k === 'proses' ? ($hitung['baru'] ?? 0) + ($hitung['formulir_lunas'] ?? 0) : ($hitung[$k] ?? 0) }})</a>
  @endforeach
</div>

<div class="grid" style="grid-template-columns:minmax(0,1fr) 340px;align-items:start">
  <div class="card"><div class="tw"><table>
    <thead><tr><th>Nomor</th><th>Calon santri</th><th>Wali</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($daftar as $p)
      <tr>
        <td class="num">{{ $p->nomor }}<br><span class="hint">{{ $p->created_at->translatedFormat('d M Y') }}</span></td>
        <td>{{ $p->nama_calon }}<br><span class="hint">{{ $p->jenis_kelamin }} · tingkat {{ $p->tingkat_tujuan }}{{ $p->asal_sekolah ? ' · '.$p->asal_sekolah : '' }}</span></td>
        <td>{{ $p->nama_wali }} ({{ $p->hubungan_wali }})<br><span class="hint num">{{ $p->telepon_wali }}</span></td>
        <td>@switch($p->status->value)
          @case('baru')<span class="chip c-info">Baru</span>@break
          @case('formulir_lunas')<span class="chip c-gold">Formulir lunas</span><br><span class="hint">{{ $rp($p->biaya_formulir) }}</span>@break
          @case('diterima')<span class="chip c-good">Diterima</span>@if ($p->santri)<br><a href="{{ route('santri.show', $p->santri) }}" class="hint">Lihat santri</a>@endif @break
          @default<span class="chip c-crit">{{ ucfirst($p->status->value) }}</span>@if ($p->catatan)<br><span class="hint">{{ $p->catatan }}</span>@endif
        @endswitch</td>
        <td class="r">
          @if ($p->status->value === 'baru')
            <details class="aksi"><summary class="btn p sm">Formulir lunas</summary>
              <form method="post" action="{{ route('pendaftaran.lunas', $p) }}" enctype="multipart/form-data" class="stack" style="margin-top:6px">@csrf
                <div class="field"><label for="b{{ $p->id }}">Biaya formulir (Rp)</label><input id="b{{ $p->id }}" name="biaya" type="number" min="0" required></div>
                <div class="field"><label for="bk{{ $p->id }}">Bukti (opsional)</label><input id="bk{{ $p->id }}" name="bukti" type="file" accept=".jpg,.jpeg,.png,.pdf"></div>
                <button class="btn p sm">Simpan</button></form>
            </details>
          @elseif ($p->status->value === 'formulir_lunas')
            <details class="aksi"><summary class="btn p sm">Terima</summary>
              <form method="post" action="{{ route('pendaftaran.terima', $p) }}" class="stack" style="margin-top:6px">@csrf
                <div class="field"><label for="k{{ $p->id }}">Kelas</label><select id="k{{ $p->id }}" name="kelas_id" required><option value="">Pilih</option>@foreach ($daftarKelas as $k)<option value="{{ $k->id }}">{{ $k->nama }}</option>@endforeach</select></div>
                <div class="field"><label for="n{{ $p->id }}">NIS</label><input id="n{{ $p->id }}" name="nis" type="text" required maxlength="20"></div>
                <div class="field"><label for="j{{ $p->id }}">Jatuh tempo DSB</label><input id="j{{ $p->id }}" name="jatuh_tempo_dsb" type="date" required value="{{ $jatuhTempoDsb }}"></div>
                <button class="btn p sm">Terima sebagai calon santri</button></form>
            </details>
          @endif
          @if (in_array($p->status->value, ['baru', 'formulir_lunas']))
            <details class="aksi"><summary class="btn d sm">Tolak</summary>
              <form method="post" action="{{ route('pendaftaran.tolak', $p) }}" class="stack" style="margin-top:6px">@csrf
                <div class="field"><label for="t{{ $p->id }}">Alasan</label><input id="t{{ $p->id }}" name="alasan" type="text" required maxlength="500"></div>
                <button class="btn d sm">Tolak</button></form>
            </details>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="5" class="muted">Belum ada pendaftaran.</td></tr>
    @endforelse
    </tbody>
  </table></div>{{ $daftar->links() }}</div>

  <form class="card stack" method="post" action="{{ route('pendaftaran.store') }}">@csrf
    <h2>Catat pendaftaran</h2><p class="hint">Untuk calon yang mendaftar langsung di kantor.</p>
    <div class="field"><label for="nc">Nama calon santri</label><input id="nc" name="nama_calon" type="text" required maxlength="100" value="{{ old('nama_calon') }}"></div>
    <div class="grid g2">
      <div class="field"><label for="jk">Jenis kelamin</label><select id="jk" name="jenis_kelamin"><option value="laki-laki">Laki-laki</option><option value="perempuan">Perempuan</option></select></div>
      <div class="field"><label for="tt">Tingkat tujuan</label><select id="tt" name="tingkat_tujuan">@foreach (range(1, 6) as $t)<option value="{{ $t }}">{{ $t }}</option>@endforeach</select></div>
    </div>
    <div class="field"><label for="as">Asal sekolah</label><input id="as" name="asal_sekolah" type="text" maxlength="100" value="{{ old('asal_sekolah') }}"></div>
    <div class="grid g2">
      <div class="field"><label for="nw">Nama wali</label><input id="nw" name="nama_wali" type="text" required maxlength="100" value="{{ old('nama_wali') }}"></div>
      <div class="field"><label for="hw">Hubungan</label><select id="hw" name="hubungan_wali"><option value="ayah">Ayah</option><option value="ibu">Ibu</option><option value="wali">Wali</option></select></div>
    </div>
    <div class="field"><label for="tw">WhatsApp wali</label><input id="tw" name="telepon_wali" type="tel" required maxlength="20" value="{{ old('telepon_wali') }}" placeholder="08…"></div>
    <div class="field"><label for="al">Alamat</label><textarea id="al" name="alamat" rows="2" required maxlength="500">{{ old('alamat') }}</textarea></div>
    <div><button class="btn p">Catat pendaftaran</button></div>
  </form>
</div>
@endsection
