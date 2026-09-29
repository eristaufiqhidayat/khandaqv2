@extends('layouts.staf')
@section('judul', 'Impor mutasi BSI')
@section('halaman')
<div class="top"><div><h1>Impor mutasi BSI</h1><p class="muted">Unggah CSV mutasi rekening. Setoran yang cocok (nominal, kode unik, tanggal) terverifikasi otomatis; sisanya ditinjau di Verifikasi setoran.</p></div>
  @if ($ditinjau)<a class="btn" href="{{ route('verifikasi.index') }}">{{ $ditinjau }} baris perlu ditinjau</a>@endif</div>
@error('file')<div class="alert err" role="alert">{{ $message }}</div>@enderror

<div class="grid g2" style="align-items:start">
  <form class="card stack" method="post" action="{{ route('bank.store') }}" enctype="multipart/form-data">
    @csrf
    <div class="card-h" style="margin:0"><h2>Unggah file</h2></div>
    <div class="field"><label for="rk">Rekening</label><select id="rk" name="rekening_id">@foreach ($rekening as $r)<option value="{{ $r->id }}" @selected($r->kode === 'BSI')>{{ $r->kode }} · {{ $r->nama }}</option>@endforeach</select></div>
    <div class="field"><label for="f">File CSV dari BSI</label><input id="f" name="file" type="file" accept=".csv,.txt" required></div>
    <details><summary class="hint" style="cursor:pointer">Pengaturan kolom (bila judul kolom di file berbeda)</summary>
      <div class="grid g2" style="margin-top:10px">
        @foreach ($kolom as $baku => $judul)
          <div class="field"><label for="k{{ $baku }}">{{ str_replace('_', ' ', ucfirst($baku)) }}</label><input id="k{{ $baku }}" name="kolom[{{ $baku }}]" type="text" value="{{ old('kolom.'.$baku, $judul) }}"></div>
        @endforeach
        <div class="field"><label for="ft">Format tanggal</label><input id="ft" name="format_tanggal" type="text" value="{{ old('format_tanggal', $formatTanggal) }}"></div>
      </div>
      <p class="hint">Contoh format: <code>d/m/Y H:i</code> untuk 27/09/2026 07:03. Baris yang sudah pernah diimpor (nomor referensi sama) dilewati.</p>
    </details>
    <div><button class="btn p">Impor & cocokkan</button></div>
  </form>

  <div class="card">
    <div class="card-h"><h2>Riwayat impor</h2></div>
    <div class="tw"><table><thead><tr><th>File</th><th>Periode</th><th class="r">Baris</th><th class="r">Cocok</th></tr></thead><tbody>
    @forelse ($riwayat as $i)
      <tr><td>{{ $i->nama_file }}<br><span class="hint">{{ $i->pengimpor?->name }} · {{ $i->created_at->translatedFormat('d M Y H:i') }}</span></td>
        <td class="num">{{ $i->periode_awal?->format('d/m') }}–{{ $i->periode_akhir?->format('d/m/Y') }}</td>
        <td class="r num">{{ $i->jumlah_baris }}</td><td class="r num">{{ $i->jumlah_cocok }}</td></tr>
    @empty
      <tr><td colspan="4" class="muted">Belum ada impor.</td></tr>
    @endforelse
    </tbody></table></div>
  </div>
</div>

<div class="card" style="margin-top:16px">
  <div class="card-h"><h2>Notifikasi email BSI</h2>
    @if ($emailAktif)<span class="chip c-good">Dibaca otomatis tiap 5 menit</span>@elseif ($email->isNotEmpty())<span class="chip c-good">Lewat pipe email</span>@else<span class="chip">Belum diatur</span>@endif</div>
  <p class="hint" style="margin-top:0">Setiap email transaksi dari BSICenter@bankbsi.co.id dicatat di sini dan langsung dicocokkan dengan laporan transfer wali, tanpa menunggu unggah CSV. Hanya email dengan tanda tangan digital (DKIM) bankbsi.co.id yang diterima.</p>
  <div class="tw"><table><thead><tr><th>Waktu</th><th>Nomor transaksi</th><th class="r">Masuk</th><th class="r">Keluar</th><th>Hasil</th></tr></thead><tbody>
  @forelse ($email as $m)
    <tr><td class="num">{{ $m->tanggal->translatedFormat('d M Y H:i') }}</td><td class="num">{{ $m->no_referensi }}</td>
      <td class="r rp">{{ $m->kredit ? 'Rp'.number_format($m->kredit, 0, ',', '.') : '—' }}</td>
      <td class="r rp">{{ $m->debet ? 'Rp'.number_format($m->debet, 0, ',', '.') : '—' }}</td>
      <td>@switch($m->status->value)
          @case('cocok')<span class="chip c-good">Cocok, setoran terverifikasi</span>@break
          @case('ditinjau')<span class="chip c-warn">Perlu ditinjau</span>@break
          @case('diabaikan')<span class="chip">Bukan setoran</span>@break
          @default<span class="chip">{{ $m->status->value }}</span>@endswitch
        @if ($m->status->value === 'ditinjau' && $m->catatan)<br><span class="hint">{{ $m->catatan }}</span>@endif</td></tr>
  @empty
    <tr><td colspan="5" class="muted">Belum ada notifikasi email yang tercatat.</td></tr>
  @endforelse
  </tbody></table></div>
</div>
@endsection
