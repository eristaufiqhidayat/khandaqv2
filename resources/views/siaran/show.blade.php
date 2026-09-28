@extends('layouts.staf')
@section('judul', $siaran->judul)
@section('halaman')
<div class="top"><div><h1>{{ $siaran->judul }}</h1><p class="muted">Status: {{ $siaran->status }} · {{ $siaran->jumlah_terkirim }} terkirim, {{ $siaran->jumlah_gagal }} gagal dari {{ $siaran->jumlah_tujuan }}</p></div>
<div class="row">
  @if ($siaran->status === 'draf')<form method="post" action="{{ route('siaran.kirim', $siaran) }}">@csrf<button class="btn p">Kirim sekarang</button></form>@endif
  @if (in_array($siaran->status, ['draf', 'mengirim']))<form method="post" action="{{ route('siaran.batal', $siaran) }}">@csrf<button class="btn d">Batalkan</button></form>@endif
  <a class="btn" href="{{ route('siaran.index') }}">Kembali</a>
</div></div>
<div class="card" style="margin-bottom:16px"><p style="white-space:pre-line">{{ $siaran->isi }}</p></div>
<div class="card"><div class="tw"><table><thead><tr><th>Wali</th><th>Nomor</th><th>Status</th><th>Keterangan</th></tr></thead><tbody>
@forelse ($pesan as $p)<tr><td>{{ $p->user?->name ?? '—' }}</td><td class="num">{{ $p->telepon }}</td><td>{{ $p->status }}</td><td class="hint">{{ $p->galat }}</td></tr>
@empty<tr><td colspan="4" class="muted">Belum dikirim.</td></tr>@endforelse
</tbody></table></div>{{ $pesan->links() }}</div>
@endsection
