<?php

namespace App\Http\Controllers\Auth;

use App\Auth\GoogleAccounts;
use App\Http\Controllers\Controller;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Events\TwoFactorAuthenticationChallenged;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\InvalidStateException;
use Symfony\Component\HttpFoundation\RedirectResponse as SymfonyRedirect;

/**
 * 用 Google 帳號登入（docs/SPEC.md T-03）。帳號的對應與建立在 App\Auth\GoogleAccounts。
 */
class GoogleLoginController extends Controller
{
    public function redirect(): SymfonyRedirect
    {
        abort_unless(GoogleAccounts::configured(), 404);

        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request, GoogleAccounts $accounts): RedirectResponse
    {
        abort_unless(GoogleAccounts::configured(), 404);

        // 在 Google 的同意畫面按了取消
        if ($request->filled('error')) {
            return $this->failed('沒有完成 Google 登入。');
        }

        try {
            $user = $accounts->resolve(Socialite::driver('google')->user());
        } catch (InvalidStateException|GuzzleException) {
            return $this->failed('Google 登入逾時或沒有完成，請再試一次。');
        } catch (ValidationException $exception) {
            return $this->failed($exception->getMessage());
        }

        // 開了雙重驗證的帳號（管理員）照樣要輸入驗證碼，和密碼登入相同（Fortify 的 two-factor.login）
        if ($user->hasEnabledTwoFactorAuthentication()) {
            $request->session()->put(['login.id' => $user->getKey(), 'login.remember' => false]);
            TwoFactorAuthenticationChallenged::dispatch($user);

            return redirect()->route('two-factor.login');
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->intended(config('fortify.home'));
    }

    private function failed(string $message): RedirectResponse
    {
        return to_route('login')->withErrors(['google' => $message]);
    }
}
