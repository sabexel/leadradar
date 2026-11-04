<?php

namespace App\Providers;

use App\Enums\ExternalService;
use App\Services\JobBoards\AdzunaService;
use App\Services\JobBoards\ReedService;
use App\Services\Support\ApiUsageLimiter;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ApiUsageLimiter::class);
        $this->app->singleton(ReedService::class);
        $this->app->singleton(AdzunaService::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureServiceRateLimits();
    }

    /**
     * Named limiters for the `RateLimited` job middleware, one per external service (docs/05 §6).
     */
    protected function configureServiceRateLimits(): void
    {
        foreach (ExternalService::cases() as $service) {
            $perMinute = config("services.{$service->value}.per_minute");

            if (is_numeric($perMinute)) {
                RateLimiter::for($service->value, fn (): Limit => Limit::perMinute((int) $perMinute));
            }
        }
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
