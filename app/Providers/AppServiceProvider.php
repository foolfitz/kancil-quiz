<?php

namespace App\Providers;

use App\Games\GameRegistry;
use App\Support\SessionHandler;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Facades\Vite;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GameRegistry::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();

        // 學生端 API 依 IP 限流，但不儲存 IP（docs/SPEC.md 10.3）。同一班的平板通常共用學校的
        // 對外 IP，所以額度要能容納全班同時作答。
        RateLimiter::for('student-api', fn (Request $request) => Limit::perMinute(1200)->by($request->ip()));
        // 檢舉（S-07）：正常使用很少需要連續送出
        RateLimiter::for('reports', fn (Request $request) => Limit::perHour(10)->by($request->ip()));

        // 工作階段不記錄 IP 與瀏覽器（App\Support\SessionHandler）
        Session::extend('database', fn (Application $app) => new SessionHandler(
            DB::connection(config('session.connection')),
            (string) config('session.table'),
            (int) config('session.lifetime'),
            $app,
        ));

        // E2E 不受本機的 Vite 開發伺服器影響（config/kancil.php）
        $hotFile = config('kancil.vite_hot_file');
        if (is_string($hotFile) && $hotFile !== '') {
            Vite::useHotFile($hotFile);
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
