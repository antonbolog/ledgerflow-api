<?php

declare(strict_types=1);

namespace Modules\Merchant\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Passport\Exceptions\OAuthServerException;
use League\OAuth2\Server\Exception\OAuthServerException as LeagueException;
use Symfony\Component\HttpFoundation\Response;

final class RequireProofKeyForCodeExchange
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $challenge = $request->query('code_challenge');

        // League requires a challenge only from public clients and reads a missing method as plain.
        if (! is_string($challenge) || $challenge === '' || $request->query('code_challenge_method') !== 'S256') {
            throw new OAuthServerException(LeagueException::invalidRequest(
                'code_challenge',
                'Authorization requests must carry a code_challenge with code_challenge_method=S256.',
            ));
        }

        return $next($request);
    }
}
