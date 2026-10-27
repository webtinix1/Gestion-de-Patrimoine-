<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        // RG-85 : 8 caractères minimum, dont un chiffre.
        Password::defaults(fn () => Password::min(8)->numbers());
    }
}
