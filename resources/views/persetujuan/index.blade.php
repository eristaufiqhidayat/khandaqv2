@extends('layouts.staf')
@section('judul', 'Persetujuan')
@php
  $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
  $jenis = ['beasiswa' => ['Beasiswa SPP', 'c-acc'], 'diskon' => ['Diskon', 'c-gold'], 'dispensasi_raport' => ['Dispensasi raport', 'c-info']];
  $nilai = function ($k) use ($rp) {
      if ($k->jenis->value === 'dispensasi_raport') {
          return ($k->semester?->nama ?? 'Semester').' · janji bayar '.$k->berlaku_sampai?->translatedFormat('d M Y');
      }
      $besar = $k->persen !== null ? rtrim(rtrim(number_format($k->persen, 2, ',', '.'), '0'), ',').'%' : $rp($k->nominal).' per tagihan';
      return $besar.' · '.($k->jenisTagihan?->nama ?? '');
  };
@endphp
@section('halaman')
<div class="top"><div><h1>Persetujuan</h1><p class="muted">Beasiswa, diskon DSB/Daftar Ulang, dan dispensasi raport baru berlaku setelah disetujui di sini.</p></div></div>
@error('persetujuan')<div class="alert err" role="alert">{{ $message }}</div>@enderror

<div class="card" style="margin-bottom:16px">
  <div class="card-h"><h2>Menunggu keputusan</h2><span class="chip {{ $menunggu->count() ? 'c-warn' : 'c-good' }}">{{ $menunggu->count() }}</span></div>
  <div class="tw"><table><thead><tr><th>Jenis</th><th>Santri</th><th>Nilai</th><th>Alasan</th><th>Diajukan</th><th></th></tr></thead><tbody>
  @forelse ($menunggu as $k)
    <tr>
      <td><span class="chip {{ $jenis[$k->jenis->value][1] }}">{{ $jenis[$k->jenis->value][0] }}</span></td>
      <td>{{ $k->santri->nama }}</td>
      <td>{{ $nilai($k) }}</td>
      <td>{{ $k->alasan }}@if ($k->lampiran_path)<br><a href="{{ route('persetujuan.lampiran', $k) }}" target="_blank" rel="noopener">Lampiran</a>@endif</td>
      <td>{{ $k->pengaju?->name }}<br><span class="hint">{{ $k->created_at->translatedFormat('d M Y') }}</span></td>
      <td class="r">
        @if ($k->diajukan_oleh === auth()->id())
          <span class="hint">Pengajuan Anda sendiri; perlu pemutus lain</span>
        @else
        <div class="row" style="justify-content:flex-end">
          <form method="post" action="{{ route('persetujuan.setujui', $k) }}">@csrf<button class="btn p sm">Setujui</button></form>
          <details class="aksi"><summary class="btn d sm">Tolak</summary>
            <form method="post" action="{{ route('persetujuan.tolak', $k) }}" class="form-baris" style="margin-top:6px">@csrf
              <div class="field"><label for="c{{ $k->id }}">Alasan penolakan</label><input id="c{{ $k->id }}" name="catatan" type="text" required maxlength="500"></div>
              <button class="btn d sm">Tolak</button></form>
          </details>
        </div>
        @endif
      </td>
    </tr>
  @empty
    <tr><td colspan="6" class="muted">Tidak ada pengajuan yang menunggu.</td></tr>
  @endforelse
  </tbody></table></div>
</div>

<div class="card">
  <div class="card-h"><h2>Keputusan terakhir</h2></div>
  <div class="tw"><table><thead><tr><th>Jenis</th><th>Santri</th><th>Nilai</th><th>Keputusan</th><th>Oleh</th></tr></thead><tbody>
  @forelse ($riwayat as $k)
    <tr>
      <td><span class="chip {{ $jenis[$k->jenis->value][1] }}">{{ $jenis[$k->jenis->value][0] }}</span></td>
      <td>{{ $k->santri->nama }}</td><td>{{ $nilai($k) }}</td>
      <td>@if ($k->status->value === 'disetujui')<span class="chip c-good">Disetujui</span>@else<span class="chip c-crit">Ditolak</span>@endif
        @if ($k->catatan_keputusan)<br><span class="hint">{{ $k->catatan_keputusan }}</span>@endif</td>
      <td>{{ $k->pemutus?->name }}<br><span class="hint">{{ $k->diputuskan_pada?->translatedFormat('d M Y H:i') }}</span></td>
    </tr>
  @empty
    <tr><td colspan="5" class="muted">Belum ada keputusan.</td></tr>
  @endforelse
  </tbody></table></div>
</div>
@endsection
