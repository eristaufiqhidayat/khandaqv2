@extends('layouts.staf')
@section('judul', 'Kasir santri')
@php
  $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
  $user = auth()->user();
  $bolehTarik = $user->hasPermissionTo(\App\Enums\Izin::PenarikanCatat->value);
  $bolehBayar = $user->hasPermissionTo(\App\Enums\Izin::TagihanKelola->value);
@endphp
@section('halaman')
<div class="top"><div><h1>Kasir santri</h1><p class="muted">Setor tunai, catat transfer, tarik uang saku, dan bayar tagihan dari saldo.</p></div></div>
@error('kasir')<div class="alert err" role="alert">{{ $message }}</div>@enderror
@error('tanggal')<div class="alert err" role="alert">{{ $message }}</div>@enderror

<div class="split">
  <div class="card stack">
    <form method="get" action="{{ route('kasir.index') }}" class="field">
      <label for="q">Cari santri (nama, NIS, atau kode transfer)</label>
      <input id="q" name="q" type="search" value="{{ $q }}" autofocus autocomplete="off">
    </form>
    @if ($q !== '')
      <div class="hasil-cari">
        @forelse ($hasil as $h)
          <a href="{{ route('kasir.index', ['q' => $q, 'santri' => $h->id]) }}" @if ($santri?->id === $h->id) aria-current="true" @endif>
            <span>{{ $h->nama }}<br><span class="hint">{{ $h->nis }}</span></span><span class="chip">{{ $h->kode_unik ?? '—' }}</span>
          </a>
        @empty
          <p class="hint">Tidak ada santri aktif yang cocok.</p>
        @endforelse
      </div>
    @endif
  </div>

  @if ($santri)
  <div class="stack">
    <div class="card">
      <div class="card-h"><div><h2>{{ $santri->nama }}</h2><span class="hint">NIS {{ $santri->nis }} · kode transfer {{ $santri->kode_unik ?? '—' }} · {{ $santri->status->value }}</span></div>
        <div style="text-align:right"><div class="eyebrow">Saldo</div><div class="rp" style="font-size:22px;font-weight:700">{{ $rp($saldo) }}</div></div></div>
      <div class="grid g2">
        <form method="post" action="{{ route('kasir.setor', $santri) }}" enctype="multipart/form-data" class="stack note">
          @csrf
          <b>Setoran</b>
          <div class="form-baris">
            <div class="field"><label for="sn">Nominal (Rp)</label><input id="sn" name="nominal" type="number" min="1000" step="1" required value="{{ old('nominal') }}"></div>
            <div class="field"><label for="sc">Cara</label><select id="sc" name="cara"><option value="tunai">Tunai (langsung masuk)</option><option value="transfer" @selected(old('cara') === 'transfer')>Transfer (menunggu verifikasi)</option></select></div>
          </div>
          <div class="field"><label for="st">Tanggal setoran</label>
            <input id="st" name="tanggal" type="date" required value="{{ old('tanggal', $hariIni->toDateString()) }}" max="{{ $hariIni->toDateString() }}"
              @if ($batasTerkunci) min="{{ $batasTerkunci->addDay()->toDateString() }}" @endif aria-describedby="st-h">
            <span class="hint" id="st-h">{{ 'Untuk transfer, isi tanggal uang dikirim.'.($batasTerkunci ? ' Buku s.d. '.$batasTerkunci->translatedFormat('F Y').' sudah ditutup.' : '') }}</span>
          </div>
          <div class="field"><label for="sb">Bukti transfer (opsional, jpg/png/pdf)</label><input id="sb" name="bukti" type="file" accept=".jpg,.jpeg,.png,.pdf"></div>
          <div><button class="btn p">Simpan setoran</button></div>
        </form>
        @if ($bolehTarik)
        <form method="post" action="{{ route('kasir.tarik', $santri) }}" class="stack note">
          @csrf
          <b>Tarik uang saku (tunai)</b>
          <div class="field"><label for="tn">Nominal (Rp)</label><input id="tn" name="nominal" type="number" min="1000" step="1" required></div>
          <div class="field"><label for="tk">Keterangan</label><input id="tk" name="keterangan" type="text" maxlength="200" placeholder="Uang saku tunai"></div>
          <div><button class="btn">Serahkan uang saku</button></div>
        </form>
        @endif
      </div>
    </div>

    <div class="card">
      <div class="card-h"><h2>Tagihan terbuka</h2><span class="hint">SPP, laundry, kesehatan terpotong otomatis saat saldo cukup</span></div>
      <div class="tw"><table><thead><tr><th>Tagihan</th><th>Jatuh tempo</th><th class="r">Sisa</th><th></th></tr></thead><tbody>
      @forelse ($tagihan as $t)
        <tr>
          <td>{{ $t->keterangan }}</td>
          <td class="num" style="{{ $t->jatuh_tempo->lt($hariIni) ? 'color:var(--crit)' : '' }}">{{ $t->jatuh_tempo->translatedFormat('d M Y') }}</td>
          <td class="r rp">{{ $rp($t->sisa()) }}</td>
          <td class="r">
            @if ($bolehBayar)
            <details class="aksi"><summary class="btn sm">Bayar dari saldo</summary>
              <form method="post" action="{{ route('kasir.bayar', [$santri, $t]) }}" class="form-baris" style="margin-top:6px">
                @csrf
                <div class="field"><label for="b{{ $t->id }}">Nominal</label><input id="b{{ $t->id }}" name="nominal" type="number" min="1" max="{{ $t->sisa() }}" value="{{ min($t->sisa(), max($saldo, 0)) }}" required></div>
                <button class="btn p sm">Bayar</button>
              </form>
              @unless ($t->jenisTagihan->boleh_dicicil)<p class="hint">Tidak boleh dicicil; bayar penuh.</p>@endunless
            </details>
            @endif
          </td>
        </tr>
      @empty
        <tr><td colspan="4" class="muted">Tidak ada tagihan terbuka.</td></tr>
      @endforelse
      </tbody></table></div>
    </div>

    <div class="card">
      <div class="card-h"><h2>Riwayat terakhir</h2></div>
      <div class="tw"><table><thead><tr><th>Waktu</th><th>Keterangan</th><th>Status</th><th class="r">Masuk</th><th class="r">Keluar</th></tr></thead><tbody>
      @forelse ($mutasi as $m)
        <tr>
          <td class="num">{{ $m->tanggal->format('d M Y H:i') }}</td>
          <td>{{ $m->keterangan }}<br><span class="hint">{{ $m->pencatat?->name ?? 'Sistem' }}</span></td>
          <td>@switch($m->status->value)
            @case('pending')<span class="chip c-warn">Menunggu verifikasi</span>@break
            @case('ditolak')<span class="chip c-crit">Ditolak</span>@break
            @default @if ($m->arah->value === 'kredit')<span class="chip c-good">Masuk saldo</span>@else<span class="chip">Dipotong dari saldo</span>@endif @endswitch</td>
          <td class="r rp">{{ $m->arah->value === 'kredit' ? $rp($m->nominal) : '' }}</td>
          <td class="r rp">{{ $m->arah->value === 'debit' ? $rp($m->nominal) : '' }}</td>
        </tr>
      @empty
        <tr><td colspan="5" class="muted">Belum ada transaksi.</td></tr>
      @endforelse
      </tbody></table></div>
    </div>
  </div>
  @else
  <div class="card"><p class="muted">Cari santri di kolom sebelah kiri untuk mulai.</p></div>
  @endif
</div>
@endsection
