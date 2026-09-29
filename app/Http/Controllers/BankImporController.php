<?php

namespace App\Http\Controllers;

use App\Enums\StatusBankMutasi;
use App\Exceptions\AturanDilanggar;
use App\Models\BankImpor;
use App\Models\BankMutasi;
use App\Models\Rekening;
use App\Services\BankMutasiMatcher;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Menu "Impor mutasi BSI" (izin bank.impor): unggah CSV mutasi rekening, lalu setiap baris kredit
 * dicocokkan otomatis dengan laporan transfer wali (nominal + kode unik + tanggal). Yang tidak cocok
 * masuk ke Verifikasi setoran untuk ditinjau. Baris yang sudah pernah diimpor dilewati.
 */
class BankImporController extends Controller
{
    public function index()
    {
        return view('bank.index', [
            'rekening' => Rekening::orderBy('kode')->get(),
            'kolom' => config('khandaq.bsi.kolom'),
            'formatTanggal' => config('khandaq.bsi.format_tanggal'),
            'riwayat' => BankImpor::with('pengimpor')->latest()->limit(15)->get(),
            'ditinjau' => BankMutasi::where('status', StatusBankMutasi::Ditinjau->value)->count(),
            'email' => BankMutasi::whereNull('bank_impor_id')->where('deskripsi', 'Notifikasi email BSI')->latest('tanggal')->limit(10)->get(),
            'emailAktif' => filled(config('khandaq.bsi.email_maildir')),
        ]);
    }

    public function store(Request $request, BankMutasiMatcher $matcher): RedirectResponse
    {
        $d = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:5120',
            'rekening_id' => 'required|exists:rekening,id',
            'format_tanggal' => 'required|string|max:30',
            'kolom' => 'array', 'kolom.*' => 'nullable|string|max:60',
        ]);
        $kolom = array_filter($d['kolom'] ?? [], fn ($v) => trim((string) $v) !== '');
        try {
            $impor = $matcher->imporCsv(Rekening::findOrFail($d['rekening_id']), $request->file('file')->getRealPath(),
                $request->user(), $kolom, $d['format_tanggal']);
        } catch (AturanDilanggar $e) {
            return back()->withInput()->withErrors(['file' => $e->getMessage()]);
        } catch (\Throwable $e) {
            return back()->withInput()->withErrors(['file' => 'File tidak bisa dibaca. Periksa nama kolom dan format tanggal. ('.mb_substr($e->getMessage(), 0, 150).')']);
        }
        $impor->update(['nama_file' => $request->file('file')->getClientOriginalName()]);
        $ditinjau = $impor->baris()->where('status', StatusBankMutasi::Ditinjau->value)->count();

        return redirect()->route('bank.index')->with('status',
            "{$impor->jumlah_baris} baris baru diimpor: {$impor->jumlah_cocok} cocok otomatis, {$ditinjau} perlu ditinjau di Verifikasi setoran.");
    }
}
