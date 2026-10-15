<?php

namespace Tests\Feature;

use App\Providers\AppServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 放在另一個反向代理後面時，只信任 TRUSTED_PROXIES（config/kancil.php）傳來的 X-Forwarded-*（docs/deploy.md）。
 */
class TrustedProxiesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_test/secure', fn (Request $request) => ['secure' => $request->isSecure()]);
    }

    private function trust(?string $proxies): void
    {
        config(['kancil.trusted_proxies' => $proxies]);
        (new AppServiceProvider($this->app))->boot();
    }

    /**
     * @return array<string, array{0: string|null, 1: string, 2: bool}>
     */
    public static function proxies(): array
    {
        return [
            '沒有設定' => [null, '172.18.0.5', false],
            '列出的網段' => ['10.1.2.3, 172.16.0.0/12', '172.18.0.5', true],
            '列出的 IP' => ['10.1.2.3, 172.16.0.0/12', '10.1.2.3', true],
            '沒有列出的' => ['10.1.2.3, 172.16.0.0/12', '192.168.1.10', false],
            '*' => ['*', '192.168.1.10', true],
        ];
    }

    #[DataProvider('proxies')]
    public function test_only_the_configured_proxies_are_trusted(?string $proxies, string $from, bool $secure): void
    {
        $this->trust($proxies);

        $this->withServerVariables(['REMOTE_ADDR' => $from])
            ->get('/_test/secure', ['X-Forwarded-Proto' => 'https'])
            ->assertJson(['secure' => $secure]);
    }
}
