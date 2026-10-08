# API aplikasi wali santri (Android) — v1

Dipakai aplikasi `lembah_arafah_v3` (modul wali). Basis URL: `https://<domain-khandaq>/api/v1`.

- Semua respons JSON (UTF-8). Kirim header `Accept: application/json`.
- Semua rute kecuali `POST /masuk` wajib header `Authorization: Bearer <token>`.
- Uang dalam rupiah bulat (integer). Tanggal `YYYY-MM-DD`.
- Galat: `{"pesan": "...", "kode": "..."}` (kode opsional). Galat validasi (422): `{"message": "...", "errors": {"kolom": ["..."]}}`.
- `401` = token tidak sah/kedaluwarsa/akun nonaktif → hapus token, kembali ke layar login.
- `404` pada `/anak/{id}` = santri itu bukan anak wali yang login.

## Token
Didapat dari `POST /masuk`, disimpan aplikasi (SharedPreferences). Berlaku 180 hari sejak terakhir dipakai
(diperpanjang otomatis). Dicabut saat `POST /keluar`, saat ganti password (perangkat lain), atau saat akun dinonaktifkan.

## Rute

### `POST /masuk`
Body (JSON atau form): `username` (username, email, atau nomor WA format apa pun), `password`, `perangkat` (opsional, mis. model HP).
Password dari aplikasi lama tetap diterima (sekali, lalu diubah ke format baru).
```json
{
  "token": "12|Xy…",
  "kedaluwarsa_pada": "2027-03-28T05:00:00+07:00",
  "wali": {"id": 5, "nama": "Sofyan Hakim", "username": "0812…", "telepon": "0812…", "telepon_menunggu_verifikasi": null,
           "email": null, "pekerjaan": "Guru", "alamat": "…", "wajib_ganti_password": false},
  "anak": [{"id": 31, "nama": "Erlangga …", "nis": "2425…", "kode_transfer": "298", "kelas": "3 PUTRA",
            "status": "aktif", "hubungan": "ayah", "foto_url": "https://…/api/v1/anak/31/foto?v=…"}]
}
```
Galat: 422 `salah`, 429 `terlalu_banyak` (5 percobaan salah / menit), 403 `bukan_wali`, 403 `akun_nonaktif`.
Bila `wali.wajib_ganti_password` true, aplikasi sebaiknya langsung membuka layar ganti password.

### `POST /masuk/google`
Masuk dengan Google (Firebase Authentication). Body: `{"id_token": "<FirebaseAuth.currentUser.getIdToken()>", "perangkat": "..."}`.
Server memeriksa tanda tangan token Google, `aud` = `FIREBASE_PROJECT_ID`, penyedia `google.com`, email terverifikasi.
Wali dicari lewat akun Google yang pernah ditautkan, lalu lewat email wali yang sama. Respons sama dengan `/masuk`
(`wajib_ganti_password` selalu `false`). Galat: `google_belum_terhubung` (404), `email_ganda` (409),
`token_google_salah` (422), `akun_nonaktif`/`bukan_wali` (403), `google_nonaktif` (503, server belum diatur).

### `POST /saya/google` · `DELETE /saya/google`
Wali yang sudah masuk menautkan / melepas akun Google (body `{"id_token": "..."}`). Email Google boleh berbeda
dengan email di Khandaq. `wali.google` = `{"terhubung": bool, "email": "..."}`.

### `POST /keluar`
Mencabut token perangkat ini.

### `GET /saya` · `PUT /saya`
`GET` → `{wali, anak}` seperti di atas.
`PUT` body: `alamat`, `pekerjaan`, `email`, `telepon` (semua opsional). Nomor WA baru **tidak** langsung berlaku:
masuk `telepon_menunggu_verifikasi` sampai disetujui Admin Office. Respons: `{pesan, wali, anak}`.

### `POST /saya/password`
Body: `password_lama`, `password_baru`, `password_baru_confirmation` (minimal 8 karakter). Token perangkat lain dicabut.

### `GET /kalender`
```json
{"tahun_ajaran": "2026/2027", "kegiatan": [{"id": 1, "tanggal_mulai": "2026-12-19", "tanggal_selesai": "2027-01-03",
  "kegiatan": "Libur akhir semester", "keterangan": null, "rentang": "19 Des – 03 Jan 2027"}]}
```

### `GET /anak/{id}`
`{"santri": {…ringkas…, "nisn", "jenis_kelamin", "tempat_lahir", "tanggal_lahir", "tanggal_masuk", "alamat"}}`

### `GET /anak/{id}/foto`
Gambar JPEG (perlu header Authorization). 404 bila belum ada foto; `foto_url` bernilai `null` bila belum ada.

### `GET /anak/{id}/tabungan?halaman=1`
```json
{
  "saldo": 540, "total_masuk": 12500000, "total_keluar": 12499460, "menunggu_verifikasi": 0,
  "rekening_transfer": {"bank": "BSI", "nomor": "…", "atas_nama": "…"}, "kode_transfer": "242",
  "tagihan": [{"id": 9, "keterangan": "SPP Oktober 2026", "jatuh_tempo": "2026-10-10", "nominal": 1400000, "sisa": 1400000, "lewat_jatuh_tempo": false}],
  "mutasi": {"data": [{"id": 77, "tanggal": "2026-09-11", "arah": "kredit", "jenis": "setoran_transfer",
             "jenis_label": "Setoran transfer", "keterangan": "…", "nominal": 1865000, "status": "terverifikasi"}],
             "halaman": 1, "halaman_terakhir": 4, "total": 97}
}
```
`arah`: `kredit` (masuk, +) / `debit` (keluar, −). `status`: `terverifikasi` atau `pending` (menunggu verifikasi; belum menambah saldo).
30 transaksi per halaman, terbaru dulu.

### `GET /anak/{id}/raport`
```json
{"raport": [{"id": 12, "jenis": "pas", "jenis_label": "Penilaian Akhir Semester", "semester": "Semester 1 (Ganjil) 2025/2026",
  "terkunci": false, "alasan_terkunci": null, "pdf_url": "https://…/api/v1/anak/31/raport/12/pdf"}]}
```
Raport tertahan bila ada tagihan wajib (SPP) yang lewat jatuh tempo, kecuali ada dispensasi: `terkunci: true`,
`alasan_terkunci` berisi pesan untuk wali, `pdf_url: null`.

### `GET /anak/{id}/raport/{raport}/pdf`
File PDF (`application/pdf`, perlu header Authorization). 403 `raport_tertahan` bila tertahan.

### `POST /anak/{id}/lapor-transfer` (multipart/form-data)
Field: `nominal` (min 1.000), `tanggal` (hari ini s.d. 60 hari lalu), `bukti` (foto JPG/PNG/WebP atau PDF, maks 8 MB), `catatan` (opsional).
Respons 201: `{"pesan": "…menunggu verifikasi…", "mutasi": {…status: "pending"…}}`. Laporan muncul di layar
**Verifikasi setoran** staf dan dicocokkan dengan mutasi BSI; saldo bertambah setelah diverifikasi.
