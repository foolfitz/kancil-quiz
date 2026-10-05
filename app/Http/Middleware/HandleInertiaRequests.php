<?php

namespace App\Http\Middleware;

use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        $admin = Filament::getPanel('admin');

        return [
            ...parent::share($request),
            'name' => config('app.name'),
            'auth' => [
                'user' => $request->user(),
                // 側邊欄的「待審題組」（docs/SPEC.md C-01）
                'canReview' => (bool) $request->user()?->hasAnyRole(['admin', 'curator']),
                // 側邊欄的「後台」：管理員與審核者（App\Models\User::canAccessPanel()）
                'adminUrl' => $request->user()?->canAccessPanel($admin) ? url($admin->getPath()) : null,
                // 設定頁的「安全性」只給有密碼的帳號（管理員）；老師用 Google 登入
                'hasPassword' => (bool) $request->user()?->hasPassword(),
            ],
            'sidebarOpen' => ! $request->hasCookie('sidebar_state') || $request->cookie('sidebar_state') === 'true',
        ];
    }
}
