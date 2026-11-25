<?php

declare(strict_types=1);

namespace KdbNetworks\Serventias;

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use KdbNetworks\Serventias\Console\Baixar;
use KdbNetworks\Serventias\Console\Importar;
use KdbNetworks\Serventias\Console\Sondar;
use KdbNetworks\Serventias\Console\Status;
use KdbNetworks\Serventias\Http\BeaconController;

class ServentiasServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/serventias.php', 'serventias');

        $this->app->singleton(Manifesto::class);
        $this->app->singleton(Beacon::class);
        $this->app->singleton(Busca::class);
        $this->app->singleton(Importador::class);
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'serventias');
        Blade::anonymousComponentPath(__DIR__.'/../resources/views/components', 'serventias');

        // Fora do guarda de console: um painel chama estes comandos por
        // Artisan::call() num pedido web, e eles precisam existir lá também.
        $this->commands([Importar::class, Status::class, Baixar::class, Sondar::class]);

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__.'/../config/serventias.php' => config_path('serventias.php')], 'serventias-config');
            $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/serventias')], 'serventias-views');
        }

        if (config('serventias.rota.ativa')) {
            Route::middleware(config('serventias.rota.middleware', ['web']))
                ->get(config('serventias.rota.caminho', '/serventias/beacon'), BeaconController::class)
                ->name('serventias.beacon');
        }
    }
}
