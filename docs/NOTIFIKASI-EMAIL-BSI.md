# Notifikasi email BSI → verifikasi setoran otomatis

BSI mengirim email ke `bsi1@lembaharafah.com` untuk setiap transaksi rekening
(Subject `NotifikasiKredit` / `NotifikasiDebit`). Khandaq membaca email itu, mencatatnya
sebagai mutasi bank, lalu mencocokkannya dengan laporan transfer wali (nominal sama,
tanggal ±2 hari, santri dari kode unik 3 digit terakhir) — sama seperti impor CSV,
tetapi beberapa menit setelah uang masuk.

## Keamanan
Hanya email dengan tanda tangan **DKIM `d=bankbsi.co.id`** yang sah yang diproses
(kunci publik diambil dari DNS `default._domainkey.bankbsi.co.id`). Email palsu atau
email asli yang isinya diubah ditolak dan dicatat di `storage/logs`.
Salinan setiap email yang diterima disimpan di `storage/app/private/bsi-email/TAHUN/BULAN/`.

## Syarat
Nomor rekening BSI harus diisi di **Rekening & akun biaya** (email hanya menampilkan
4 digit terakhir, misalnya `XXXXXX3330`).

## Cara A — cron membaca folder email (disarankan)
Bila akun email `bsi1@lembaharafah.com` ada di akun cPanel yang sama dengan aplikasi:

```
# .env
BSI_EMAIL_MAILDIR=/home/lembaha1/mail/lembaharafah.com/bsi1
```

Cron `schedule:run` yang sudah ada akan menjalankan `khandaq:bsi-email` tiap 5 menit
(email 3 hari terakhir; yang sudah tercatat dilewati). Uji manual & isi riwayat:

```
php84 artisan khandaq:bsi-email --hari=60
```

## Cara B — pipe cPanel (seketika)
cPanel → Email → Forwarders → Add Forwarder → alamat `bsi1` →
"Pipe to a Program" → `khandaqv2.lembaharafah.com/laravel/bin/bsi-email`.
Email tetap masuk ke kotak masuk seperti biasa. Hasil dicatat di `storage/logs/laravel.log`.

## Catatan
- Sinkronisasi data lama mengosongkan `bank_mutasi`; setelah sinkron jalankan
  `khandaq:bsi-email --hari=60` agar riwayat email terisi kembali.
- Debet (uang keluar) dicatat dengan status *Bukan setoran*.
- Kredit tanpa laporan wali masuk antrean **Verifikasi setoran → perlu ditinjau**,
  dengan santri terdeteksi bila 3 digit terakhir nominal = kode unik.
