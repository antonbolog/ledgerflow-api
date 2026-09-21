<?php

declare(strict_types=1);

namespace Modules\Merchant\Http\Controllers;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Redirect;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final readonly class MerchantLoginController
{
    public function __construct(private AuthFactory $auth) {}

    public function show(): View
    {
        return view('merchant.login');
    }

    public function store(Request $request): RedirectResponse
    {
        /** @var array{email: string, password: string} $credentials */
        $credentials = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'password' => ['required', 'string'],
        ]);

        $attempted = $this->guard()->attempt([
            'email' => self::normaliseEmail($credentials['email']),
            'password' => $credentials['password'],
        ]);

        if (! $attempted) {
            // One message for both causes: an unauthenticated caller must not learn which
            // merchant identities exist.
            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        $request->session()->regenerate();

        return Redirect::intended('/');
    }

    public static function normaliseEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function guard(): StatefulGuard
    {
        $guard = $this->auth->guard('merchant_web');

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('The merchant_web guard must be a session guard.');
        }

        return $guard;
    }
}
