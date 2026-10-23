<?php

namespace App\Providers;


use Illuminate\Support\ServiceProvider;
use Filament\Events\ServingFilament;
use App\Listeners\RegenerateShieldPermissions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Toda vez que o Filament for carregado, o Shield recria permissões
        Event::listen(
            ServingFilament::class,
            RegenerateShieldPermissions::class
        );

	if (app()->environment('production') || config('app.force_https', false)) {
              URL::forceScheme('https');
    	}
    }
}
