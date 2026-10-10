<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

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
        // Login, OTP and registration: limit per IP and per target email so a
        // single account cannot be brute-forced from many addresses either.
        RateLimiter::for('auth', function (Request $request) {
            $email = strtolower(trim((string) $request->input('email')));

            return [
                Limit::perMinute(10)->by('auth-ip:'.$request->ip()),
                Limit::perMinute(5)->by('auth-email:'.$email.'|'.$request->path()),
                Limit::perHour(30)->by('auth-email-hour:'.$email),
            ];
        });

        RateLimiter::for('public', fn (Request $request) => Limit::perMinute(60)->by('public:'.$request->ip()));

        RateLimiter::for('api', function (Request $request) {
            $user = $request->attributes->get('authUser');

            return Limit::perMinute(180)->by('api:'.($user->public_id ?? $request->ip()));
        });
    }
}
