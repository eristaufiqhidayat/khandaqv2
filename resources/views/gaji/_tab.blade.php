<div class="seg" role="group" aria-label="Bagian">
  <a class="segbtn" href="{{ route('gaji.index') }}" aria-pressed="{{ $aktif === 'gaji' ? 'true' : 'false' }}">Penggajian</a>
  <a class="segbtn" href="{{ route('gaji.pegawai') }}" aria-pressed="{{ $aktif === 'pegawai' ? 'true' : 'false' }}">Data pegawai</a>
</div>
