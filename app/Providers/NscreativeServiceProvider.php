<?php

namespace App\Providers;

use App\Nscreative\Src\Classes\Nscreative;
use Illuminate\Support\ServiceProvider;

class NscreativeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton('Nscreative', function () {
            return new Nscreative;
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
