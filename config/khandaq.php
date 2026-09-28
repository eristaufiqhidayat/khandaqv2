<?php

return [
    // 'paralel' = aplikasi lama masih dipakai; modul Sinkronisasi data lama boleh menimpa data.
    // Ubah ke 'produksi' saat cut-over: modul sinkronisasi terkunci permanen.
    'mode' => env('KHANDAQ_MODE', 'paralel'),

    // Koneksi database aplikasi lama (lihat config/database.php, koneksi 'lama').
    'koneksi_lama' => env('KHANDAQ_KONEKSI_LAMA', 'lama'),

    // Batas penarikan uang saku tunai per santri per hari (rupiah). 0 = tanpa batas.
    'batas_tarik_harian' => env('KHANDAQ_BATAS_TARIK_HARIAN', 0),

    // Toleransi selisih tanggal antara laporan setoran wali dan mutasi BSI.
    'toleransi_hari_bank' => env('KHANDAQ_TOLERANSI_HARI_BANK', 2),

    // Database login aplikasi lama (Myth/Auth, lembaha1_igni399). Bila diisi, sinkronisasi membawa
    // password wali yang sudah ada sehingga wali tetap masuk dengan password lamanya. Kosongkan untuk melewati.
    'koneksi_login_lama' => env('KHANDAQ_KONEKSI_LOGIN_LAMA'),

    // WhatsApp. Semua kunci di .env, JANGAN di repo atau di tabel (aplikasi lama menyimpannya di tbl_myapp).
    'whatsapp' => [
        'vendor' => env('WA_VENDOR', 'log'),               // ngirimwa | meta | log (tidak mengirim)
        'ngirimwa' => [
            'appkey' => env('WA_NGIRIMWA_APPKEY'),
            'authkey' => env('WA_NGIRIMWA_AUTHKEY'),
        ],
        'meta' => [
            'phone_number_id' => env('WA_META_PHONE_NUMBER_ID'),
            'token' => env('WA_META_TOKEN'),                // token System User (permanen), bukan token 24 jam
            'app_secret' => env('WA_META_APP_SECRET'),      // untuk memeriksa tanda tangan webhook
            'verify_token' => env('WA_META_VERIFY_TOKEN'),  // bebas, sama dengan yang diisi di dashboard Meta
            'versi' => env('WA_META_VERSI', 'v21.0'),
        ],
        'webhook_kunci' => env('WA_WEBHOOK_KUNCI'),        // kunci acak di URL webhook ngirimwa
        'template_peringatan' => env('WA_TEMPLATE_PERINGATAN', 'pemberitahuan_wali'),
        'jeda_detik' => env('WA_JEDA_DETIK', 3),            // jeda antar-nomor saat siaran
    ],
];
