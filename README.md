# Khandaq v2 — Tabungan Santri (Laravel 13)

Proyek Laravel lengkap untuk aplikasi Tabungan Santri Khandaq, sesuai dokumen *Khandaq — Proses Bisnis & Rencana Upgrade*:
skema database, aturan bisnis, sinkronisasi data lama, WhatsApp, halaman masuk, dan rute per peran.

## Cara pasang

```bash
unzip khandaq-laravel.zip && cd khandaq-laravel
cp .env.example .env          # isi DB_*, DB_LAMA_*, WA_* (lihat di bawah)
composer install              # PHP >= 8.3, ekstensi pdo_mysql, mbstring, openssl
php artisan key:generate
php artisan migrate --seed    # tabel + peran/izin + master keuangan
php artisan khandaq:buat-admin eris --nama="Eris Taufiq" --telepon=08xxxxxxxxxx
```

Perintah terakhir mencetak **password sementara** Admin sekali saja; saat masuk pertama Admin wajib menggantinya.
Setelah itu Admin membuat akun staf lain dari aplikasi, dan akun wali terbentuk dari Sinkronisasi data lama atau Pendaftaran santri baru.

Di cPanel: arahkan document root domain/subdomain ke folder `public/`. Tambahkan cron:

```
* * * * * cd /home/USER/khandaq-laravel && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USER/khandaq-laravel && php artisan queue:work --stop-when-empty --max-time=55 >> /dev/null 2>&1
```

Antrean dipakai oleh Sinkronisasi data lama dan Siaran WhatsApp.

## Rute

| URL | Siapa | Isi |
| --- | --- | --- |
| `/` | semua | Tamu diarahkan ke `/masuk`; yang sudah masuk ke `/beranda` |
| `/masuk` | tamu | Satu halaman masuk untuk staf dan wali. Kolom username menerima username, email, atau nomor WhatsApp (format apa pun). Maks. 5 percobaan salah per menit |
| `/beranda` | semua | Wali ke `/wali`; staf ke menu pertama yang ia punya izinnya (Keuangan ke Ringkasan keuangan, Admin Office ke Kasir, Admin ke Tarif). Staf tanpa izin melihat halaman "Belum ada akses" |
| `/ganti-password` | semua | Wajib dibuka dulu bila password masih sementara (middleware `WajibGantiPassword`) |
| `/wali` | wali santri | Saldo, tagihan terbuka, dan raport setiap anak (raport tertahan bila SPP belum lunas) |
| `/ringkasan` | izin `laporan.lihat` | Ringkasan keuangan |
| `/siaran`, `/sinkronisasi` | izin `wa.siaran`, `migrasi.jalankan` | Siaran WhatsApp, Sinkronisasi data lama |
| menu staf lainnya | sesuai izin | Rute & izin sudah aktif; layarnya berstatus "sedang dibangun" |
| `/webhook/wa/*` | gateway | Tanpa login, tanpa CSRF, diperiksa tanda tangan/kunci |

Menu sidebar dan izin rute berasal dari satu sumber (`config/khandaq-menu.php` + `routes/web.php`), jadi memindahkan izin di Hak akses otomatis memindahkan menu dan akses rutenya.

`.env` yang perlu diisi: `DB_*` (MySQL/MariaDB, **InnoDB**, utf8mb4), `DB_LAMA_*` (user SELECT saja), `WA_VENDOR` + kunci gateway (lihat *WhatsApp*),
opsional `KHANDAQ_BATAS_TARIK_HARIAN`, `KHANDAQ_TOLERANSI_HARI_BANK`, `KHANDAQ_KONEKSI_LOGIN_LAMA=login_lama`.

## Isi

| Bagian | Berkas | Catatan |
| --- | --- | --- |
| Pendaftaran | `pendaftaran` | Formulir PSB; diterima langsung jadi santri & akun wali (kakak-adik ditautkan lewat nomor WA) |
| Master | `tahun_ajaran`, `semester`, `kelas`, `santri`, `santri_kelas`, `wali_santri` | TA 1 Juli–30 Juni; satu wali bisa banyak anak |
| Master keuangan | `dana`, `rekening`, `akun`, `pengusul`, `jenis_tagihan`, `tarif` | Besaran sesuai kebijakan di tabel `tarif`, bukan di kode |
| Potongan otomatis | `pengecualian_potongan`, `jeda_potongan` | Opsi "tidak dijalankan" per santri / massal |
| Keringanan | `keringanan` | Beasiswa, diskon DSB/DU, dispensasi raport; berlaku setelah disetujui Keuangan |
| Tagihan | `tagihan` | Jatuh tempo SPP akhir bulan, tanpa denda |
| Ledger | `tabungan_mutasi` | Saldo = kredit terverifikasi − debit; tidak pernah disimpan |
| Bank | `bank_impor`, `bank_mutasi` | Impor CSV BSI + pencocokan kode unik |
| Kontrol | `tutup_buku`, audit log | Bulan yang ditutup terkunci |
| Operasional | `raport`, `peringatan`, `pengeluaran` | Raport terkunci; tahapan warning 1/2/3 bulan |
| WhatsApp | `wa_siaran`, `wa_pesan`, `wa_webhook` | Siaran ke wali, status per nomor, balasan wali, callback mentah |

Service utama (`app/Services`): `TagihanGenerator`, `TabunganService`, `KeringananService`,
`AksesRaport` (dipakai `RaportPolicy`), `BankMutasiMatcher`, `PeringatanService`,
`PotonganOtomatisService`, `TutupBukuService`, `LaporanCashflow`, `DataSantriService`, `PeriodeService`, `PendaftaranService`, `DataWaliService`, `AkunService`, `HakAksesService`, `LaporanTagihan` (menu Status pembayaran: kisi SPP Juli–Juni per santri, sisa DSB/DU, angka kartu Ringkasan).

## Siapa mengelola data master

| Data | Dientri oleh | Persetujuan | Izin |
| --- | --- | --- | --- |
| Pendaftaran santri baru | Calon wali (formulir online) atau Admin Office | Admin Office menerima → santri calon + akun wali + tagihan DSB otomatis | `pendaftaran.proses` |
| Tambah wali (wali kedua, ganti wali, tautkan ke adik) | Admin Office, menu Data santri & wali → Wali | — ; nomor WA yang sudah terdaftar ditautkan, bukan akun baru | `santri.kelola` |
| Data wali (alamat, pekerjaan) | Wali di portal | — ; nomor WhatsApp baru menunggu verifikasi Admin Office | `data_wali.verifikasi` |
| Data santri, akun wali, penempatan & kenaikan kelas, status keluar | Admin Office | — (pemulangan karena tunggakan: keputusan Keuangan dulu) | `santri.kelola` |
| Kode unik transfer | Otomatis saat santri ditambah; dibebaskan saat lulus/keluar | — | — |
| Daftar kelas | Admin | — | `kelas.kelola` |
| Tahun ajaran & semester | Otomatis (Juli–Juni); Admin mengaktifkan periode berjalan | — | `periode.kelola` |
| Tarif, jenis potongan, pengecualian | Admin | — | `tarif.kelola`, `pengecualian.kelola` |
| Beasiswa SPP | Admin | Keuangan | `keringanan.ajukan` / `keringanan.setujui` |
| Akun staf & peran, reset password staf | Admin | — | `pengguna.kelola` |
| Reset password / nonaktifkan akun wali | Admin Office | — | `akun_wali.reset` |
| Rekening, akun biaya, pengusul | Keuangan | — | `master_keuangan.kelola` |

## Sinkronisasi data lama (masa paralel)

Menu Admin **Sinkronisasi data lama** (izin `migrasi.jalankan`): satu klik menyalin **semua** data dari `lembaha1_sino` ke tabel baru dan **mengganti** salinan sebelumnya. Bisa diulang setiap hari selama paralel run.

- Koneksi `lama` dan `login_lama` sudah ada di `config/database.php` (diisi dari `DB_LAMA_*`). Beri user ini hak **SELECT saja** ke database lama.
- Yang diganti: semua data aplikasi baru (santri, wali, kelas, periode, tarif, beasiswa, tagihan, buku tabungan, pengeluaran, mutasi BSI, raport). Yang dipertahankan: akun staf, peran & izin, dana, rekening, jenis tagihan.
- Terkunci bila `KHANDAQ_MODE=produksi` atau sudah ada tutup buku. Satu transaksi database: gagal di tengah = data baru tidak berubah.
- Tabel samping (tbl_pts, tbl_pas, tbl_iuran_loundry, tbl_daftar_ulang2, tbl_uang_buku, tbl_dsb) dicocokkan dengan buku tabungan: salinan potongan tabungan tidak digandakan; yang dibayar di luar tabungan dicatat sebagai setoran + pembayaran.
- Hasil uji terhadap data asli (anonim): 94.457 dari 94.461 transaksi tersalin (4 tanpa buku tabungan/santri), saldo 353/353 santri sama persis (total Rp50.258.956), ±45 detik per jalan.
- Password wali TIDAK dikirim saat sinkronisasi (agar tidak mengirim WhatsApp setiap hari). Pengiriman akses ke semua wali dilakukan sekali saat go-live.
- Baris perintah: `php artisan khandaq:sinkron-data-lama --admin=<username>`.
- **Password wali lama (opsional).** Isi `KHANDAQ_KONEKSI_LOGIN_LAMA=login_lama` dan tambahkan koneksi `login_lama` ke `lembaha1_igni399` (SELECT saja). Wali yang sudah punya akun (username sama dengan `data_orangtua.username`, `ortu = '1'`, aktif) membawa hash Myth/Auth-nya ke kolom `password_lama`; saat pertama masuk, `AkunService::cocokkanPassword` memeriksanya dengan cara Myth/Auth (bcrypt atas base64(sha384)) lalu langsung menggantinya dengan hash Laravel. Password lama di bawah 8 karakter diterima sekali, lalu wajib diganti. Wali tanpa akun lama menerima password sementara saat go-live.

## WhatsApp

Pengganti menu *Whatsapp Manager* dan database `lembaha1_daftar_siswa` (kecuali `tbl_daftar_baru`, lihat peta tabel).

- **Gateway** dipilih lewat `WA_VENDOR`: `ngirimwa` (`WA_NGIRIMWA_APPKEY`, `WA_NGIRIMWA_AUTHKEY`), `meta` (`WA_META_PHONE_NUMBER_ID`, `WA_META_TOKEN` token System User permanen, `WA_META_APP_SECRET`, `WA_META_VERIFY_TOKEN`), atau `log` (tidak mengirim; untuk lokal). Kunci tidak lagi disimpan di tabel (`tbl_myapp`).
- **Siaran** (menu *Siaran WhatsApp*, izin `wa.siaran`, bawaan Admin Office): sasaran semua wali, per kelas, atau wali yang anaknya menunggak ≥ N bulan. Tujuan diambil dari nomor wali di data santri (bukan buku telepon terpisah), satu pesan per nomor, `{nama_wali}` dan `{nama_santri}` diisi otomatis. Dikirim lewat antrean (`KirimPesanWa`) dengan jeda `WA_JEDA_DETIK` antar-nomor. Bila gateway Meta, siaran wajib memakai template yang disetujui Meta; isi siaran menjadi parameter `{{1}}`.
- **Peringatan tunggakan** dikirim lewat gateway yang sama dan tercatat di `wa_pesan` dengan `peringatan_id`. Status *dibaca* dari Meta mengisi `peringatan.dibaca_pada` (bukti baca untuk keputusan pemulangan).
- **Webhook** (sudah terdaftar di `routes/web.php`, tanpa login dan CSRF): `GET|POST /webhook/wa/meta` (daftarkan di Meta; tanda tangan `X-Hub-Signature-256` diperiksa) dan `POST /webhook/wa/ngirimwa?kunci=WA_WEBHOOK_KUNCI`.
  Status (terkirim → diterima → dibaca, atau gagal) tidak pernah mundur walau callback datang tidak berurutan; balasan wali disimpan sebagai `wa_pesan` arah `masuk` dan ditautkan ke akun wali lewat nomornya.

## Hak akses (bisa diubah Admin)

Peran & izin tersimpan di database (spatie). Menu staf didefinisikan di `config/khandaq-menu.php` dan tampil berdasarkan **izin**, bukan nama peran; route dijaga `permission:<izin>`. Maka Admin bisa memindahkan menu (mis. Tarif dari Admin ke Keuangan) lewat layar Hak akses (`HakAksesService`) tanpa mengubah kode. Pengaman: Admin selalu memegang `hak_akses.kelola` & `pengguna.kelola`; wali tidak bisa diberi izin staf; setiap izin minimal dipegang satu peran; kombinasi sensitif (ajukan + setujui) diberi peringatan; semua perubahan tercatat di audit log.

## Akun & password

- Tidak ada petugas yang mengetik atau mengetahui password orang lain. Password awal dan reset dibuat acak oleh sistem (`AkunService`) dan dikirim ke WhatsApp pemilik.
- Semua pengguna wajib mengganti password saat login pertama (middleware `WajibGantiPassword`, sudah terdaftar di `bootstrap/app.php`), minimal 8 karakter.
- Admin pertama dibuat dari terminal: `php artisan khandaq:buat-admin <username>` (juga untuk me-reset password Admin yang lupa).
- Akun staf: dibuat, di-reset, dan dinonaktifkan oleh Admin (`pengguna.kelola`).
- Akun wali: dibuat otomatis saat pendaftaran diterima (username = nomor WhatsApp); di-reset atau dinonaktifkan oleh Admin Office (`akun_wali.reset`). Admin Office tidak bisa me-reset akun staf.
- Migrasi dari aplikasi lama: akun wali dibuat ulang oleh sinkronisasi. Bila koneksi login lama diisi, wali tetap masuk dengan password lamanya (lihat di atas); sisanya menerima password sementara lewat WhatsApp saat go-live.
- Halaman masuk (`MasukController`) memakai `AkunService::cocokkanPassword`, bukan `Auth::attempt`, agar password lama dikenali.

## Aturan yang ditegakkan kode (dan dites)

- Setoran transfer berstatus *pending* sampai cocok dengan mutasi BSI atau diverifikasi orang lain dari pencatat.
- Setelah kredit terverifikasi: infak per setoran → SPP → laundry → kesehatan dilunasi otomatis; SPP tidak dicicil otomatis.
- Penarikan tunai tidak boleh membuat saldo negatif (opsional batas harian).
- Beasiswa (input Admin), diskon DSB/DU, dispensasi raport: hanya disetujui izin `keringanan.setujui` (Keuangan), dan pengaju ≠ penyetuju.
- Raport terkunci bila ada SPP lewat jatuh tempo; dispensasi membuka raport tanpa menghapus tunggakan.
- Tunggakan 3 bulan: kandidat pemulangan + pemberitahuan resmi; keputusan oleh Keuangan setelah wali konfirmasi baca.
- DSB dan Daftar Ulang: satu mekanisme tagihan, dua jenis tagihan & dua dana.
- Tutup buku berurutan, menolak bila masih ada setoran pending; transaksi bulan tertutup tidak bisa ditambah/diubah/dihapus.

## Peta tabel lama → baru (untuk skrip migrasi berikutnya)

| Lama (`lembaha1_sino`) | Baru |
| --- | --- |
| `data_siswa`, `tbl_ruangan`, `tbl_ruangan_periode`, `tbl_ruangan_20xx` | `santri` (`legacy_id_siswa`), `santri_kelas` |
| `setup_periode` | `tahun_ajaran` + `semester` (`legacy_id_periode`) |
| `lembaha1_daftar_siswa.tbl_daftar_baru` (61 calon, 2024; tanpa jenis kelamin & tahun ajaran) | tidak dimigrasi (arsip); pendaftaran baru lewat formulir `pendaftaran` |
| `tbl_siswa_baru`, `tbl_formulir` | `pendaftaran` |
| `lembaha1_daftar_siswa.tbl_groupwa`, `tbl_nama_wa` | tidak dimigrasi: tujuan siaran = nomor wali di `users` |
| `lembaha1_daftar_siswa.tbl_message`, `tbl_log_status`, `tbl_webhook` | `wa_siaran`, `wa_pesan`, `wa_webhook` (riwayat 2024 tidak disalin) |
| `lembaha1_daftar_siswa.tbl_myapp`, `tbl_template` | `.env` (kunci) dan nama template di `wa_siaran.template` |
| `lembaha1_igni399.users` (`ortu = '1'`) | `users.password_lama` (hash Myth/Auth, dipakai sekali) |
| `lembaha1_igni399.auth_groups*`, `tbl_menu`, `tbl_module` | peran & izin spatie + `config/khandaq-menu.php` |
| `data_orangtua`, `tbl_akses_ortu`, `users` (Myth/Auth) | `users` (`legacy_id_orangtua`) + `wali_santri` |
| `tbl_tabungan_transaksi` + `tbl_kode_transaksi` | `tabungan_mutasi` (`legacy_notransaksi`, `legacy_kode`) |
| `TKSPP/TKTAB/TKTRF`, `TKTNI` | `setoran_transfer`, `setoran_tunai` |
| `TDTNI`, `TDPRE` | `penarikan_tunai`, `pengembalian` |
| `TDSPP/TDLDR/TDKES/TDINF/TDPTS/TDPAS/TDADM` | `pembayaran_tagihan` + baris `tagihan` |
| `TKKOR/TDKOR`, `TKPTS/TKPAS` | `koreksi` / `transfer_dana` |
| `tbl_tarif_spp`, hardcode laundry/kesehatan/infak | `tarif` |
| `tbl_beasiswa` (`rp` = SPP yang dibayar) | `keringanan` beasiswa (`nominal` = tarif − rp) |
| `tbl_dsb`, `tbl_daftar_ulang2`, `tbl_pts`, `tbl_pas`, `tbl_uang_buku`, `tbl_iuran_loundry` | `tagihan` + `tabungan_mutasi` |
| `tbl_pengeluaran*` (6 tabel) | `pengeluaran` (`dana_id`, `legacy_ref`) |
| `tbl_mutasi_bsi` | `bank_mutasi` |
| `tbl_nilai_akhir` (BLOB) | `raport` (file di Storage, `legacy_id_nilai`) |
| `tbl_spp`, `tbl_validasi`, `tbl_tabungan_siswa.rp`, `*_bak`, `V*` | tidak dimigrasi (arsip) |

## Tentang pengujian

```bash
php artisan test
```

56 tes di proyek Laravel 13.33 dengan paket asli (spatie/laravel-permission 8, spatie/laravel-activitylog 5): 52 lulus,
termasuk `RuteDanMasukTest` (arah halaman utama, masuk per peran, akun nonaktif, batas percobaan, wajib ganti password,
password aplikasi lama, izin per menu, webhook). 4 tes `MigrasiDataLamaTest` dilewati karena membutuhkan salinan database lama;
tes itu dijalankan terpisah di lingkungan pembuatan terhadap data asli yang dianonimkan dan lulus (saldo 353/353 santri sama).
