<?php

namespace App\Support;

/**
 * Pemeriksa tanda tangan DKIM (RFC 6376) untuk email mentah.
 *
 * Dipakai agar notifikasi mutasi yang diterima benar-benar dikirim server bank: email palsu yang
 * meniru isi notifikasi tidak punya kunci privat bank sehingga tanda tangannya tidak sah.
 * Mendukung rsa-sha256 dengan kanonikalisasi simple/relaxed. Tanda tangan ber-tag l= (panjang body
 * terbatas) ditolak karena memungkinkan teks tambahan di bawah bagian yang ditandatangani.
 */
final class Dkim
{
    /** @var array<string, ?string> */
    private static array $cacheKunci = [];

    /**
     * @param  (callable(string): ?string)|null  $txt  pencari TXT DNS "selector._domainkey.domain" (untuk uji)
     */
    public static function sah(string $raw, string $domain, ?callable $txt = null): bool
    {
        $raw = str_replace(["\r\n", "\r"], "\n", $raw);
        $raw = str_replace("\n", "\r\n", $raw);
        $pisah = strpos($raw, "\r\n\r\n");
        if ($pisah === false) {
            return false;
        }
        $kepala = substr($raw, 0, $pisah);
        $body = substr($raw, $pisah + 4);

        preg_match_all('/^([^:\s]+):[^\r\n]*(?:\r\n[ \t][^\r\n]*)*/m', $kepala, $m, PREG_SET_ORDER);
        $header = array_map(fn ($x) => ['nama' => $x[1], 'baris' => $x[0]], $m);

        foreach ($header as $h) {
            if (strcasecmp($h['nama'], 'DKIM-Signature') === 0 && self::periksa($h['baris'], $header, $body, $domain, $txt)) {
                return true;
            }
        }

        return false;
    }

    private static function periksa(string $sig, array $header, string $body, string $domain, ?callable $txt): bool
    {
        $tag = [];
        foreach (explode(';', substr($sig, strpos($sig, ':') + 1)) as $p) {
            if (str_contains($p, '=')) {
                [$k, $v] = explode('=', $p, 2);
                $tag[strtolower(trim($k))] = preg_replace('/\s+/', '', $v);
            }
        }
        foreach (['v', 'a', 'd', 's', 'h', 'bh', 'b'] as $wajib) {
            if (! isset($tag[$wajib]) || $tag[$wajib] === '') {
                return false;
            }
        }
        if ($tag['v'] !== '1' || strtolower($tag['a']) !== 'rsa-sha256' || strcasecmp($tag['d'], $domain) !== 0 || isset($tag['l'])) {
            return false;
        }
        $daftarH = array_map('trim', explode(':', strtolower($tag['h'])));
        if (! in_array('from', $daftarH, true)) {
            return false;
        }
        [$cHeader, $cBody] = array_pad(explode('/', strtolower($tag['c'] ?? 'simple/simple')), 2, 'simple');

        // Body
        if ($cBody === 'relaxed') {
            $b = preg_replace('/[ \t]+\r\n/', "\r\n", preg_replace('/[ \t]+/', ' ', $body));
            $b = rtrim($b, "\r\n");
            $b = $b === '' ? '' : $b."\r\n";
        } else {
            $b = preg_replace('/(\r\n)*$/', '', $body)."\r\n";
        }
        if (! hash_equals(base64_encode(hash('sha256', $b, true)), $tag['bh'])) {
            return false;
        }

        // Header: tiap nama di h= mengambil kemunculan dari bawah ke atas.
        $kanon = fn (string $baris) => $cHeader === 'relaxed'
            ? strtolower(trim(substr($baris, 0, strpos($baris, ':')))).':'.trim(preg_replace('/[ \t]+/', ' ', str_replace("\r\n", '', substr($baris, strpos($baris, ':') + 1))))
            : $baris;
        $data = '';
        $terpakai = [];
        foreach ($daftarH as $nama) {
            for ($i = count($header) - 1; $i >= 0; $i--) {
                if (! isset($terpakai[$i]) && strcasecmp($header[$i]['nama'], $nama) === 0) {
                    $data .= $kanon($header[$i]['baris'])."\r\n";
                    $terpakai[$i] = true;
                    break;
                }
            }
        }
        // Header DKIM-Signature sendiri ikut ditandatangani dengan nilai b= dikosongkan, tanpa CRLF penutup.
        [$namaSig, $nilaiSig] = explode(':', $sig, 2);
        $nilaiSig = preg_replace('/((?:^|;)\s*b\s*=)[^;]*/', '$1', $nilaiSig, 1);
        $data .= $kanon($namaSig.':'.$nilaiSig);

        $kunci = self::kunci($tag['s'].'._domainkey.'.strtolower($tag['d']), $txt);
        if ($kunci === null) {
            return false;
        }

        return openssl_verify($data, (string) base64_decode($tag['b'], true), $kunci, OPENSSL_ALGO_SHA256) === 1;
    }

    private static function kunci(string $host, ?callable $txt): ?string
    {
        $isi = $txt ? $txt($host) : (self::$cacheKunci[$host] ??= self::dns($host));
        if (! $isi || ! preg_match('/(?:^|;)\s*p=([A-Za-z0-9+\/=\s]+)/', $isi, $m)) {
            return null;
        }
        $p = preg_replace('/\s+/', '', $m[1]);

        return $p === '' ? null : "-----BEGIN PUBLIC KEY-----\n".chunk_split($p, 64, "\n")."-----END PUBLIC KEY-----\n";
    }

    private static function dns(string $host): ?string
    {
        $r = @dns_get_record($host, DNS_TXT);
        if (! $r) {
            return null;
        }
        foreach ($r as $baris) {
            $t = isset($baris['entries']) ? implode('', $baris['entries']) : ($baris['txt'] ?? '');
            if (str_contains($t, 'p=')) {
                return $t;
            }
        }

        return null;
    }
}
