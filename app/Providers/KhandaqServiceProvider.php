<?php

namespace App\Providers;

use App\Contracts\Notifier;
use App\Contracts\PenyimpanIzinPeran;
use App\Contracts\WaGateway;
use App\Services\Whatsapp\PengirimWa;
use App\Whatsapp\LogGateway;
use App\Whatsapp\MetaCloudGateway;
use App\Whatsapp\NgirimwaGateway;
use App\Support\SpatieIzinPeran;
use App\Notifiers\LogNotifier;
use App\Notifiers\WhatsappNotifier;
use App\Services\BankMutasiMatcher;
use App\Services\TabunganService;
use Illuminate\Support\ServiceProvider;

/** Daftarkan di bootstrap/providers.php. */
class KhandaqServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(TabunganService::class, fn () => new TabunganService((int) config('khandaq.batas_tarik_harian')));

        $this->app->singleton(BankMutasiMatcher::class, fn ($app) => new BankMutasiMatcher(
            $app->make(TabunganService::class), (int) config('khandaq.toleransi_hari_bank'),
        ));

        $this->app->bind(PenyimpanIzinPeran::class, SpatieIzinPeran::class);

        $this->app->singleton(WaGateway::class, function () {
            $wa = config('khandaq.whatsapp');

            return match ($wa['vendor']) {
                'ngirimwa' => new NgirimwaGateway((string) $wa['ngirimwa']['appkey'], (string) $wa['ngirimwa']['authkey']),
                'meta' => new MetaCloudGateway((string) $wa['meta']['phone_number_id'], (string) $wa['meta']['token'], (string) $wa['meta']['versi']),
                default => new LogGateway(),
            };
        });
        $this->app->singleton(PengirimWa::class, fn ($app) => new PengirimWa(
            $app->make(WaGateway::class), config('khandaq.whatsapp.template_peringatan'),
        ));
        $this->app->singleton(Notifier::class, fn ($app) => config('khandaq.whatsapp.vendor') === 'log'
            ? new LogNotifier()
            : new WhatsappNotifier($app->make(PengirimWa::class)));
    }
}
