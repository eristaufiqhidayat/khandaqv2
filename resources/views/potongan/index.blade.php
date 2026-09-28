@extends('layouts.staf')
@section('judul', 'Potongan otomatis')
@section('halaman')
<div class="top"><div><h1>Potongan otomatis</h1><p class="muted">{{ $jenis->pluck('nama')->join(', ') }} dipotong otomatis dari tabungan. Di sini opsi "tidak dijalankan": per santri atau sementara untuk semua.</p></div></div>
@error('potongan')<div class="alert err" role="alert">{{ $message }}</div>@enderror
<div class="grid g2" style="align-items:start">
  <div class="stack">
    <div class="card"><div class="card-h"><h2>Pengecualian per santri</h2><span class="chip">{{ $pengecualian->count() }}</span></div>
      <div class="tw"><table><thead><tr><th>Santri</th><th>Jenis</th><th>Rentang</th><th></th></tr></thead><tbody>
      @forelse ($pengecualian as $p)
        <tr><td>{{ $p->santri->nama }}<br><span class="hint">{{ $p->alasan }}</span></td><td>{{ $p->jenisTagihan->nama }}</td>
          <td class="num">{{ $p->mulai->translatedFormat('d M Y') }} – {{ $p->selesai?->translatedFormat('d M Y') ?? 'seterusnya' }}</td>
          <td class="r"><form method="post" action="{{ route('potongan.akhiri', $p) }}">@csrf<button class="btn sm">Akhiri</button></form></td></tr>
      @empty<tr><td colspan="4" class="muted">Tidak ada pengecualian aktif.</td></tr>@endforelse
      </tbody></table></div></div>
    <div class="card"><div class="card-h"><h2>Jeda massal</h2></div>
      <div class="tw"><table><thead><tr><th>Jenis</th><th>Rentang</th><th>Alasan</th></tr></thead><tbody>
      @forelse ($jeda as $j)<tr><td>{{ $j->jenisTagihan->nama }}</td><td class="num">{{ $j->mulai->translatedFormat('d M Y') }} – {{ $j->selesai->translatedFormat('d M Y') }}</td><td>{{ $j->alasan }}</td></tr>
      @empty<tr><td colspan="3" class="muted">Tidak ada jeda aktif.</td></tr>@endforelse
      </tbody></table></div></div>
  </div>
  <div class="stack">
    <form class="card stack" method="post" action="{{ route('potongan.kecualikan') }}">@csrf
      <h2>Kecualikan santri</h2><p class="hint">Mis. santri tidak ikut laundry. Tagihan dalam rentang itu yang belum dibayar ikut dibatalkan.</p>
      <div class="field"><label for="s">Santri</label><select id="s" name="santri_id" required><option value="">Pilih santri</option>@foreach ($daftarSantri as $s)<option value="{{ $s->id }}">{{ $s->nama }} ({{ $s->nis }})</option>@endforeach</select></div>
      <div class="field"><label for="j1">Jenis</label><select id="j1" name="jenis_tagihan_id">@foreach ($jenis as $j)<option value="{{ $j->id }}">{{ $j->nama }}</option>@endforeach</select></div>
      <div class="grid g2"><div class="field"><label for="m1">Mulai</label><input id="m1" name="mulai" type="date" required value="{{ now()->startOfMonth()->toDateString() }}"></div>
        <div class="field"><label for="s1">Selesai (kosong = seterusnya)</label><input id="s1" name="selesai" type="date"></div></div>
      <div class="field"><label for="a1">Alasan</label><input id="a1" name="alasan" type="text" required maxlength="255"></div>
      <div><button class="btn p">Simpan pengecualian</button></div>
    </form>
    <form class="card stack" method="post" action="{{ route('potongan.jeda') }}">@csrf
      <h2>Jeda untuk semua santri</h2><p class="hint">Mis. laundry tidak ditagih selama libur panjang.</p>
      <div class="field"><label for="j2">Jenis</label><select id="j2" name="jenis_tagihan_id">@foreach ($jenis as $j)<option value="{{ $j->id }}">{{ $j->nama }}</option>@endforeach</select></div>
      <div class="grid g2"><div class="field"><label for="m2">Mulai</label><input id="m2" name="mulai" type="date" required></div>
        <div class="field"><label for="s2">Selesai</label><input id="s2" name="selesai" type="date" required></div></div>
      <div class="field"><label for="a2">Alasan</label><input id="a2" name="alasan" type="text" required maxlength="255"></div>
      <div><button class="btn p">Simpan jeda</button></div>
    </form>
  </div>
</div>
@endsection
