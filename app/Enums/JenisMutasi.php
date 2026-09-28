<?php

namespace App\Enums;

enum JenisMutasi: string
{
    case SetoranTransfer = 'setoran_transfer';     // TKSPP, TKTAB, TKTRF (lama)
    case SetoranTunai = 'setoran_tunai';           // TKTNI
    case PenarikanTunai = 'penarikan_tunai';       // TDTNI (uang saku)
    case PembayaranTagihan = 'pembayaran_tagihan'; // TDSPP, TDLDR, TDKES, TDINF, TDPTS, TDPAS, TDADM
    case Koreksi = 'koreksi';                      // TKKOR, TDKOR
    case TransferDana = 'transfer_dana';           // dana DSB/DU dipindah ke tabungan (dulu TKKOR "SPP Juli dari DSB")
    case Pengembalian = 'pengembalian';            // TDPRE: saldo dikembalikan ke wali
    case SaldoAwal = 'saldo_awal';                 // hasil migrasi / rekonsiliasi

    public function arah(): ArahMutasi
    {
        return match ($this) {
            self::SetoranTransfer, self::SetoranTunai, self::TransferDana, self::SaldoAwal => ArahMutasi::Kredit,
            self::PenarikanTunai, self::PembayaranTagihan, self::Pengembalian => ArahMutasi::Debit,
            self::Koreksi => throw new \LogicException('Arah koreksi harus ditentukan eksplisit.'),
        };
    }
}
