@extends('layouts.staf')
@section('judul', 'Siaran WhatsApp')
@section('halaman')
<div class="top"><div><h1>Siaran WhatsApp</h1><p class="muted">Kirim pemberitahuan ke wali santri. Nomor tujuan diambil dari data wali yang sudah diverifikasi.</p></div></div>
<div class="grid g2" style="align-items:start">
  <form class="card stack" method="post" action="{{ route('siaran.store') }}">
    @csrf
    <div class="card-h" style="margin:0"><h2>Siaran baru</h2><span class="chip c-info">Gateway: {{ config('khandaq.whatsapp.vendor') }}</span></div>
    <div class="field"><label for="sj">Judul (untuk riwayat)</label><input id="sj" name="judul" type="text" value="{{ old('judul') }}" required maxlength="100"></div>
    <div class="field"><label for="jenis">Kirim ke</label>
      <select id="jenis" name="jenis"><option value="semua_wali">Semua wali</option><option value="kelas">Per kelas</option><option value="tunggakan">Wali yang anaknya menunggak</option></select></div>
    <div class="field"><label for="kelas">Kelas (bila per kelas)</label><select id="kelas" name="kelas_ids[]" multiple size="4">@foreach ($kelas as $k)<option value="{{ $k->id }}">{{ $k->nama }}</option>@endforeach</select></div>
    <div class="field"><label for="min">Menunggak minimal (bulan, bila penunggak)</label><input id="min" name="min_bulan" type="number" min="1" max="12" value="1"></div>
    @if (config('khandaq.whatsapp.vendor') === 'meta')<div class="field"><label for="tpl">Template Meta</label><input id="tpl" name="template" type="text" required></div>@endif
    <div class="field"><label for="isi">Isi pesan</label><textarea id="isi" name="isi" rows="6" required>{{ old('isi', "Assalamu'alaikum Bapak/Ibu {nama_wali},\n") }}</textarea><span class="hint">{nama_wali} dan {nama_santri} diisi otomatis per penerima.</span></div>
    <div class="row" style="justify-content:flex-end"><button class="btn p">Simpan draf</button></div>
  </form>
  <div class="card"><div class="card-h"><h2>Riwayat siaran</h2></div><div class="tw"><table><thead><tr><th>Siaran</th><th>Status</th><th class="r">Terkirim</th><th class="r">Gagal</th></tr></thead><tbody>
  @forelse ($siaran as $s)<tr><td><a href="{{ route('siaran.show', $s) }}">{{ $s->judul }}</a><br><span class="hint">{{ $s->created_at->format('d M Y H:i') }}</span></td><td>{{ $s->status }}</td><td class="r num">{{ $s->jumlah_terkirim }} / {{ $s->jumlah_tujuan }}</td><td class="r num">{{ $s->jumlah_gagal ?: '—' }}</td></tr>
  @empty<tr><td colspan="4" class="muted">Belum ada siaran.</td></tr>@endforelse
  </tbody></table></div>{{ $siaran->links() }}</div>
</div>
@endsection
