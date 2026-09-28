@extends('layouts.staf')
@section('judul', 'Sinkronisasi data lama')
@section('halaman')
<div class="top"><div><h1>Sinkronisasi data lama</h1><p class="muted">Menyalin semua data dari aplikasi lama ke aplikasi baru dan mengganti salinan sebelumnya. Dipakai selama masa paralel.</p></div></div>
@php($aktif = $riwayat->first(fn ($r) => $r->aktif()))
@if ($aktif)
  <div class="card stack" style="margin-bottom:16px">
    <div class="card-h" style="margin:0"><h2>{{ $aktif->status === 'antri' ? 'Menunggu diproses' : 'Sedang berjalan' }}</h2><span class="hint">{{ $aktif->tahap ?? 'Belum dimulai' }} · {{ $aktif->persen ?? 0 }}%</span></div>
    @if ($aktif->menungguPekerja())
      <div class="note warn"><b>Belum diproses sejak {{ $aktif->created_at->diffForHumans() }}.</b> Pekerja antrean belum berjalan. Di server jalankan
        <code>php artisan queue:work --stop-when-empty --timeout=1800</code>, atau batalkan lalu ulangi setelah pekerja antrean aktif.</div>
    @endif
    <form method="post" action="{{ route('sinkronisasi.batal', $aktif) }}" onsubmit="return confirm('Batalkan proses ini? Bila ternyata masih berjalan, hasilnya tetap bisa selesai sendiri.')">@csrf<button class="btn d">Batalkan proses</button></form>
  </div>
  <script>setTimeout(() => location.reload(), 5000)</script>
@endif
@if ($mode !== 'paralel')
  <div class="alert err">Aplikasi sudah mode produksi. Sinkronisasi terkunci.</div>
@else
<form class="card stack" method="post" action="{{ route('sinkronisasi.store') }}" id="fSync" style="margin-bottom:16px">
  @csrf
  <div class="note warn"><b>Semua data di aplikasi baru akan diganti.</b> Yang tetap: akun staf, peran &amp; hak akses, dana, rekening, jenis tagihan, kegiatan kalender yang diisi di aplikasi baru. Password wali tidak dikirim.</div>
  <div class="field" style="max-width:280px"><label for="kg">Ketik GANTI untuk melanjutkan</label><input id="kg" name="konfirmasi" type="text" autocomplete="off" required pattern="GANTI"></div>
  <div><button class="btn p">Salin ulang semua data</button></div>
  <p class="hint" id="progres" aria-live="polite"></p>
</form>
@endif
<div class="card"><div class="card-h"><h2>Riwayat</h2></div><div class="tw"><table><thead><tr><th>Mulai</th><th>Status</th><th>Tahap</th><th class="r">Saldo sama</th></tr></thead><tbody>
@forelse ($riwayat as $r)<tr><td class="num">{{ $r->mulai_pada?->format('d M Y H:i') ?? '—' }}</td><td>{{ $r->status }}</td><td>{{ $r->galat ?? $r->tahap }}</td><td class="r num">{{ $r->rekonsiliasi ? $r->rekonsiliasi['sama'].' / '.$r->rekonsiliasi['santri'] : '—' }}</td></tr>
@empty<tr><td colspan="4" class="muted">Belum pernah dijalankan.</td></tr>@endforelse
</tbody></table></div></div>
<script>
const f=document.getElementById('fSync');
if(f)f.onsubmit=async e=>{e.preventDefault();const p=document.getElementById('progres');
  const r=await fetch(f.action,{method:'POST',headers:{'Accept':'application/json'},body:new FormData(f)});const j=await r.json();
  if(!r.ok){p.textContent=j.pesan||j.message||'Gagal';return}
  const t=setInterval(async()=>{const s=await (await fetch('{{ url('sinkronisasi') }}/'+j.run,{headers:{'Accept':'application/json'}})).json();
    p.textContent=(s.tahap||'Menunggu antrean')+' · '+(s.persen||0)+'%';
    if(['selesai','gagal'].includes(s.status)){clearInterval(t);location.reload()}},2000)};
</script>
@endsection
