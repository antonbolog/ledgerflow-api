<?php

declare(strict_types=1);

namespace Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EndMerchantAuthorizationSession
{
    public function __construct(private AuthFactory $auth) {}

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $guard = $this->auth->guard('merchant_web');

        if (! $guard instanceof StatefulGuard) {
            return $next($request);
        }

        // Read before the request runs: a guest must keep the session holding its intended URL,
        // and an authenticated merchant must lose it however the attempt ends.
        $authenticated = $guard->check();

        $response = $next($request);

        if ($authenticated) {
            $guard->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return $response;
    }
}
