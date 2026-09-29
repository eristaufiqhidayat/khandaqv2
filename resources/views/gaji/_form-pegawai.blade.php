<div class="field"><label for="nm">Nama lengkap</label><input id="nm" name="nama" type="text" required maxlength="100" value="{{ old('nama', $pg?->nama) }}"></div>
<div class="field"><label for="jb">Jabatan / tugas</label><input id="jb" name="jabatan" type="text" maxlength="100" value="{{ old('jabatan', $pg?->jabatan) }}" placeholder="Guru SMP, Bendahara, ..."></div>
<div class="field"><label for="rk">Nomor rekening BSI</label><input id="rk" name="no_rekening" type="text" inputmode="numeric" required maxlength="35" value="{{ old('no_rekening', $pg?->no_rekening) }}"></div>
<div class="field"><label for="nr">Nama pemilik rekening</label><input id="nr" name="nama_rekening" type="text" maxlength="100" value="{{ old('nama_rekening', $pg?->nama_rekening) }}">
  <span class="hint">Kosongkan bila sama dengan nama lengkap.</span></div>
<div class="field"><label for="em">Email notifikasi</label><input id="em" name="email" type="email" maxlength="100" value="{{ old('email', $pg?->email) }}"></div>
<div class="field"><label for="tl">Nomor HP notifikasi SMS</label><input id="tl" name="telepon" type="tel" maxlength="20" value="{{ old('telepon', $pg?->telepon) }}" placeholder="08..."></div>
<div class="field"><label for="nt">Nominal tetap per bulan (Rp)</label><input id="nt" name="nominal_tetap" type="number" min="0" step="1000" value="{{ old('nominal_tetap', $pg?->nominal_tetap) }}">
  <span class="hint">Usulan awal saat membuat penggajian; tetap bisa diubah per bulan.</span></div>
