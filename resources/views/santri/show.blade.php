@extends('layouts.staf')
@section('judul', $santri->nama)
@php
  $rp = fn ($n) => 'Rp'.number_format((int) $n, 0, ',', '.');
  $bolehReset = auth()->user()->hasPermissionTo(\App\Enums\Izin::AkunWaliReset->value);
@endphp
@section('halaman')
<div class="top"><div><h1>{{ $santri->nama }}</h1><p class="muted">NIS {{ $santri->nis }} · kode transfer {{ $santri->kode_unik ?? '—' }} · <span class="chip {{ ['aktif' => 'c-good', 'calon' => 'c-info', 'keluar' => 'c-crit'][$santri->status->value] ?? '' }}">{{ ucfirst($santri->status->value) }}</span></p></div>
  <div class="row"><a class="btn" href="{{ route('santri.edit', $santri) }}">Ubah biodata</a><a class="btn" href="{{ route('santri.index') }}">Kembali</a></div></div>
@if ($errors->has('santri') || $errors->has('wali'))<div class="alert err" role="alert">{{ $errors->first('santri') ?: $errors->first('wali') }}</div>@endif

<div class="grid g2" style="align-items:start">
  <div class="stack">
    <div class="card">
      <div style="display:flex;gap:16px;align-items:flex-start;flex-wrap:wrap">
        @include('santri._foto', ['s' => $santri, 'ukuran' => 'lg'])
        <div class="stack" style="flex:1;min-width:220px">
          <form method="post" action="{{ route('santri.foto.simpan', $santri) }}" enctype="multipart/form-data" class="form-baris">@csrf
            <div class="field" style="flex:1"><label for="foto">{{ $santri->foto ? 'Ganti foto' : 'Unggah foto' }}</label>
              <input id="foto" name="foto" type="file" accept="image/jpeg,image/png,image/webp,image/gif" required></div>
            <div><button class="btn p">Simpan foto</button></div>
          </form>
          <p class="hint">JPG/PNG/WebP, maks. 8 MB. Diperkecil otomatis; tampil juga di portal wali.</p>
          @if ($santri->foto)<form method="post" action="{{ route('santri.foto.hapus', $santri) }}" onsubmit="return confirm('Hapus foto {{ addslashes($santri->nama) }}?')">@csrf<button class="btn sm d">Hapus foto</button></form>@endif
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-h"><h2>Biodata</h2><div style="text-align:right"><div class="eyebrow">Saldo</div><div class="rp" style="font-weight:700">{{ $rp($saldo) }}</div></div></div>
      <div class="tw"><table><tbody>
        <tr><td class="hint">Jenis kelamin</td><td>{{ ucfirst($santri->jenis_kelamin) }}</td></tr>
        <tr><td class="hint">Tempat, tanggal lahir</td><td>{{ $santri->tempat_lahir ?? '—' }}{{ $santri->tanggal_lahir ? ', '.$santri->tanggal_lahir->translatedFormat('d F Y') : '' }}</td></tr>
        <tr><td class="hint">NIK / NISN</td><td class="num">{{ $santri->nik ?? '—' }} / {{ $santri->nisn ?? '—' }}</td></tr>
        <tr><td class="hint">Asal sekolah</td><td>{{ $santri->asal_sekolah ?? '—' }}</td></tr>
        <tr><td class="hint">Tanggal masuk</td><td>{{ $santri->tanggal_masuk?->translatedFormat('d F Y') ?? '—' }}</td></tr>
        <tr><td class="hint">Alamat</td><td>{{ $santri->alamat ?? '—' }}</td></tr>
        <tr><td class="hint">Riwayat kelas</td><td>@foreach ($santri->riwayatKelas->sortByDesc('tahun_ajaran_id') as $r){{ $r->tahunAjaran?->nama }}: {{ $r->kelas?->nama }}<br>@endforeach</td></tr>
        @if ($santri->catatan)<tr><td class="hint">Catatan</td><td style="white-space:pre-line">{{ $santri->catatan }}</td></tr>@endif
      </tbody></table></div>
    </div>

    @if ($santri->status->value === 'calon')
    <form class="card stack" method="post" action="{{ route('santri.aktifkan', $santri) }}">@csrf
      <h2>Aktifkan santri</h2><p class="hint">Setelah aktif, tagihan bulanan (SPP, laundry, kesehatan) terbit untuknya.</p>
      <div class="form-baris"><div class="field"><label for="tmk">Tanggal mulai mondok</label><input id="tmk" name="tanggal_masuk" type="date" required value="{{ now()->toDateString() }}"></div><button class="btn p">Aktifkan</button></div>
    </form>
    @endif

    @if (in_array($santri->status->value, ['aktif', 'calon']))
    <details class="card"><summary style="cursor:pointer"><b>Santri keluar / dipulangkan</b></summary>
      <form method="post" action="{{ route('santri.keluarkan', $santri) }}" class="stack" style="margin-top:10px" onsubmit="return confirm('Catat {{ $santri->nama }} keluar? Kode transfer akan dibebaskan.')">@csrf
        <div class="field"><label for="alk">Alasan</label><input id="alk" name="alasan" type="text" required maxlength="300"></div>
        <label class="cek"><input type="checkbox" name="karena_tunggakan" value="1"> Pemulangan karena tunggakan (perlu keputusan Keuangan di menu Tunggakan)</label>
        <div><button class="btn d">Catat keluar</button></div>
      </form>
    </details>
    @endif
  </div>

  <div class="stack">
    <div class="card">
      <div class="card-h"><h2>Wali</h2>@unless ($waOn)<span class="chip c-warn">WhatsApp belum diatur</span>@endunless</div>
      @forelse ($santri->wali as $w)
        <div class="ph-line"><span><b>{{ $w->name }}</b> · {{ $w->pivot->hubungan }}<br>
          <span class="hint">{{ $w->telepon ?? 'tanpa nomor WA' }}{{ $w->telepon_menunggu ? ' · nomor baru menunggu: '.$w->telepon_menunggu : '' }} · username {{ $w->username }}{{ $w->aktif ? '' : ' · nonaktif' }}</span></span>
          <span class="row" style="gap:6px">
            <a class="btn sm" href="{{ route('walisantri.edit', ['wali' => $w, 'kembali' => route('santri.show', $santri)]) }}">Ubah</a>
            @if ($bolehReset)<form method="post" action="{{ route('santri.wali.reset', [$santri, $w]) }}" onsubmit="return confirm('Reset password {{ $w->name }}? Password baru dikirim ke WhatsApp-nya.')">@csrf<button class="btn sm">Reset password</button></form>@endif
            @if ($santri->wali->count() > 1)<form method="post" action="{{ route('santri.wali.lepas', [$santri, $w]) }}" onsubmit="return confirm('Lepas {{ $w->name }} dari {{ $santri->nama }}?')">@csrf<button class="btn sm">Lepas</button></form>@endif
          </span></div>
      @empty
        <p class="note warn">Belum ada wali. Tambahkan agar wali bisa melihat saldo dan menerima pemberitahuan.</p>
      @endforelse
    </div>

    <form class="card stack" method="post" action="{{ route('santri.wali.store', $santri) }}">@csrf
      <h2>Tambah wali</h2>
      <p class="hint">Bila nomor WhatsApp sudah terdaftar (misalnya kakaknya sudah mondok), santri ditautkan ke akun yang ada. Akun baru mendapat password sementara lewat WhatsApp.</p>
      <div class="grid g2">
        <div class="field"><label for="wn">Nama</label><input id="wn" name="name" type="text" required maxlength="100" value="{{ old('name') }}"></div>
        <div class="field"><label for="wt">Nomor WhatsApp</label><input id="wt" name="telepon" type="tel" required maxlength="20" value="{{ old('telepon') }}" placeholder="08…"></div>
        <div class="field"><label for="wh">Hubungan</label><select id="wh" name="hubungan"><option value="ayah">Ayah</option><option value="ibu" @selected(old('hubungan') === 'ibu')>Ibu</option><option value="wali" @selected(old('hubungan') === 'wali')>Wali</option></select></div>
        <div class="field"><label for="wp">Pekerjaan</label><input id="wp" name="pekerjaan" type="text" maxlength="100" value="{{ old('pekerjaan') }}"></div>
        <div class="field"><label for="we">Email (opsional)</label><input id="we" name="email" type="email" maxlength="100" value="{{ old('email') }}"></div>
        <div class="field"><label for="wk">NIK (opsional)</label><input id="wk" name="nik" type="text" inputmode="numeric" maxlength="16" value="{{ old('nik') }}"></div>
      </div>
      <div class="field"><label for="wa">Alamat</label><input id="wa" name="alamat" type="text" maxlength="300" value="{{ old('alamat', $santri->alamat) }}"></div>
      <div><button class="btn p">Simpan wali</button></div>
    </form>
  </div>
</div>
@endsection
