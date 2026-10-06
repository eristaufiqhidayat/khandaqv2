@extends('layouts.staf')
@section('judul', 'Sinkronisasi data lama')
@section('halaman')
<div class="top"><div><h1>Sinkronisasi data lama</h1><p class="muted">Menyalin semua data dari aplikasi lama ke aplikasi baru dan mengganti salinan sebelumnya. Dipakai selama masa paralel.</p></div></div>
@php($aktif = $riwayat->first(fn ($r) => $r->aktif()))
@if ($aktif)
  @php($menit = intdiv($aktif->lamaDetik() ?? 0, 60))
  <div class="card stack" style="margin-bottom:16px" id="kartuProgres" data-url="{{ route('sinkronisasi.show', $aktif) }}" data-tunggu="{{ $aktif->menungguPekerja() ? 1 : 0 }}">
    <div class="card-h" style="margin:0"><h2 id="pJudul">{{ $aktif->status === 'antri' ? 'Menunggu diproses' : 'Sedang berjalan' }}</h2>
      <span class="hint num" id="pLama">{{ $aktif->mulai_pada ? sprintf('%d:%02d', $menit, ($aktif->lamaDetik() ?? 0) % 60).' berjalan' : '' }}</span></div>
    <div class="progres{{ $aktif->status === 'antri' ? ' tunggu' : '' }}" id="pBar" role="progressbar" aria-label="Kemajuan sinkronisasi" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $aktif->persen ?? 0 }}">
      <div class="progres-isi" style="width:{{ $aktif->status === 'antri' ? 100 : max(2, $aktif->persen ?? 0) }}%"></div>
    </div>
    <div class="progres-ket"><span id="pTahap">{{ $aktif->tahap ?? 'Menunggu pekerja antrean' }}</span><b class="num" id="pPersen">{{ $aktif->persen ?? 0 }}%</b></div>
    @if ($aktif->menungguPekerja())
      <div class="note warn"><b>Belum diproses sejak {{ $aktif->created_at->diffForHumans() }}.</b> Pekerja antrean belum berjalan. Di server jalankan
        <code>php artisan queue:work --stop-when-empty --timeout=1800</code>, atau batalkan lalu ulangi setelah pekerja antrean aktif.</div>
    @endif
    <p class="hint">Halaman ini boleh ditutup; proses tetap berjalan di server.</p>
    <form method="post" action="{{ route('sinkronisasi.batal', $aktif) }}" onsubmit="return confirm('Batalkan proses ini? Bila ternyata masih berjalan, hasilnya tetap bisa selesai sendiri.')">@csrf<button class="btn d">Batalkan proses</button></form>
  </div>
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
if(f)f.onsubmit=async e=>{e.preventDefault();const p=document.getElementById('progres'),b=f.querySelector('button');
  b.disabled=true;p.textContent='Memulai…';
  try{const r=await fetch(f.action,{method:'POST',headers:{'Accept':'application/json'},body:new FormData(f)});const j=await r.json();
    if(!r.ok){p.textContent=j.pesan||j.message||'Gagal';b.disabled=false;return}
    location.reload();
  }catch(_){p.textContent='Gagal menghubungi server. Coba lagi.';b.disabled=false}};

const k=document.getElementById('kartuProgres');
if(k){const $=id=>document.getElementById(id),bar=$('pBar'),isi=bar.firstElementChild;
  const jam=d=>d==null?'':Math.floor(d/60)+':'+String(d%60).padStart(2,'0')+' berjalan';
  const tik=async()=>{try{
      const s=await (await fetch(k.dataset.url,{headers:{'Accept':'application/json'}})).json();
      if(['selesai','gagal'].includes(s.status)){location.reload();return}
      if(s.menunggu_pekerja&&k.dataset.tunggu!=='1'){location.reload();return}
      const antri=s.status==='antri',n=s.persen||0;
      bar.classList.toggle('tunggu',antri);
      isi.style.width=(antri?100:Math.max(2,n))+'%';
      bar.setAttribute('aria-valuenow',n);
      $('pJudul').textContent=antri?'Menunggu diproses':'Sedang berjalan';
      $('pTahap').textContent=s.tahap||'Menunggu pekerja antrean';
      $('pPersen').textContent=n+'%';
      $('pLama').textContent=jam(s.lama_detik);
    }catch(_){}
    setTimeout(tik,2000)};
  setTimeout(tik,2000)}
</script>
@endsection
