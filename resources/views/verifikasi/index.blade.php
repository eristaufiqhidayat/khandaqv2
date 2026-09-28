@extends('layouts.staf')
@section('judul', 'Verifikasi setoran')
@php($rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.'))
@section('halaman')
<div class="top"><div><h1>Verifikasi setoran</h1><p class="muted">Transfer yang cocok dengan mutasi BSI terverifikasi otomatis. Yang tersisa di sini perlu diputuskan.</p></div></div>
@error('verifikasi')<div class="alert err" role="alert">{{ $message }}</div>@enderror

<div class="stack">
  <div class="card">
    <div class="card-h"><h2>Laporan transfer menunggu</h2><span class="chip {{ $pending->count() ? 'c-warn' : 'c-good' }}">{{ $pending->count() }}</span></div>
    <div class="tw"><table><thead><tr><th>Santri</th><th>Tanggal</th><th class="r">Nominal</th><th>Dicatat oleh</th><th>Bukti</th><th></th></tr></thead><tbody>
    @forelse ($pending as $m)
      <tr>
        <td>{{ $m->santri->nama }}<br><span class="hint">kode {{ $m->santri->kode_unik ?? '—' }}</span></td>
        <td class="num">{{ $m->tanggal->format('d M Y H:i') }}</td>
        <td class="r rp">{{ $rp($m->nominal) }}</td>
        <td>{{ $m->pencatat?->name ?? '—' }}</td>
        <td>@if ($m->bukti_path)<a href="{{ route('setoran.bukti', $m) }}" target="_blank" rel="noopener">Lihat</a>@else<span class="hint">—</span>@endif</td>
        <td class="r">
          @if ($m->dicatat_oleh === auth()->id())
            <span class="hint">Dicatat oleh Anda; perlu petugas lain</span>
          @else
          <div class="row" style="justify-content:flex-end">
            <form method="post" action="{{ route('verifikasi.setoran', $m) }}">@csrf<button class="btn p sm">Verifikasi</button></form>
            <details class="aksi"><summary class="btn d sm">Tolak</summary>
              <form method="post" action="{{ route('verifikasi.tolak', $m) }}" class="form-baris" style="margin-top:6px">@csrf
                <div class="field"><label for="al{{ $m->id }}">Alasan (dikirim ke wali)</label><input id="al{{ $m->id }}" name="alasan" type="text" required maxlength="200" value="Transfer tidak ditemukan di rekening BSI pondok"></div>
                <button class="btn d sm">Tolak setoran</button></form>
            </details>
          </div>
          @endif
        </td>
      </tr>
    @empty
      <tr><td colspan="6" class="muted">Tidak ada laporan transfer yang menunggu.</td></tr>
    @endforelse
    </tbody></table></div>
  </div>

  <div class="card">
    <div class="card-h"><h2>Mutasi BSI perlu ditinjau</h2><span class="chip {{ $ditinjau->count() ? 'c-warn' : 'c-good' }}">{{ $ditinjau->count() }}</span></div>
    <div class="tw"><table><thead><tr><th>Waktu</th><th>Deskripsi</th><th class="r">Kredit</th><th>Catatan</th><th></th></tr></thead><tbody>
    @forelse ($ditinjau as $b)
      <tr>
        <td class="num">{{ $b->tanggal->format('d M Y H:i') }}<br><span class="hint">{{ $b->no_referensi }}</span></td>
        <td>{{ $b->deskripsi }}</td>
        <td class="r rp">{{ $rp($b->kredit) }}</td>
        <td class="hint">{{ $b->catatan }}</td>
        <td class="r">
          <details class="aksi"><summary class="btn p sm">Terima sebagai setoran</summary>
            <form method="post" action="{{ route('verifikasi.terima', $b) }}" class="form-baris" style="margin-top:6px">@csrf
              <div class="field"><label for="s{{ $b->id }}">Santri</label>
                <select id="s{{ $b->id }}" name="santri_id" required><option value="">Pilih santri</option>
                @foreach ($daftarSantri as $s)<option value="{{ $s->id }}" @selected($b->santri_id_terdeteksi === $s->id)>{{ $s->nama }} ({{ $s->kode_unik ?? '—' }})</option>@endforeach</select></div>
              <button class="btn p sm">Terima</button></form>
          </details>
          <details class="aksi"><summary class="btn sm">Bukan setoran</summary>
            <form method="post" action="{{ route('verifikasi.abaikan', $b) }}" class="form-baris" style="margin-top:6px">@csrf
              <div class="field"><label for="c{{ $b->id }}">Catatan</label><input id="c{{ $b->id }}" name="catatan" type="text" required maxlength="200" placeholder="mis. biaya admin bank"></div>
              <button class="btn sm">Simpan</button></form>
          </details>
        </td>
      </tr>
    @empty
      <tr><td colspan="5" class="muted">Tidak ada mutasi yang perlu ditinjau.</td></tr>
    @endforelse
    </tbody></table></div>
  </div>
</div>
@endsection
