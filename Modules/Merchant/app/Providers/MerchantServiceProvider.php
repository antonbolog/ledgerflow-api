<?php

declare(strict_types=1);

namespace Modules\Merchant\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Auth\StatefulGuard;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Passport\Bridge\RefreshTokenRepository;
use Laravel\Passport\Passport;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Merchant\Http\Controllers\MerchantAuthorizationController;
use Modules\Merchant\Http\Controllers\MerchantLoginController;
use Modules\Merchant\Infrastructure\Console\IssueDemoTokenCommand;
use Modules\Merchant\Infrastructure\Console\ProvisionBffClientCommand;
use Modules\Merchant\Infrastructure\Console\SetDemoPasswordCommand;
use Modules\Merchant\Infrastructure\Identity\AuthenticatedMerchantContext;
use Modules\Merchant\Infrastructure\Persistence\AtomicRefreshTokenRepository;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\OAuthClient;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;
use Override;
use RuntimeException;

class MerchantServiceProvider extends ServiceProvider
{
    private const int BFF_TOKEN_REQUESTS_PER_MINUTE = 600;

    private const int ANONYMOUS_TOKEN_REQUESTS_PER_MINUTE = 60;

    private const int LOGIN_ATTEMPTS_PER_MINUTE = 5;

    #[Override]
    public function register(): void
    {
        $this->app->scoped(
            MerchantContext::class,
            fn (Application $app): AuthenticatedMerchantContext => new AuthenticatedMerchantContext(
                $app->make(AuthFactory::class),
                'api',
            ),
        );

        Passport::useClientModel(OAuthClient::class);

        // Passport resolves the bridge repository by its concrete name when it builds the grants.
        $this->app->bind(RefreshTokenRepository::class, AtomicRefreshTokenRepository::class);

        // Passport binds this contextually to its own controller class, and contextual bindings
        // are not inherited by a subclass.
        $this->app->when(MerchantAuthorizationController::class)
            ->needs(StatefulGuard::class)
            ->give(fn (Application $app): StatefulGuard => self::sessionGuard($app->make(AuthFactory::class)));
    }

    public function boot(): void
    {
        // The alias is what Passport's owner morphs store in oauth_clients.owner_type; renaming it needs a data migration.
        // morphMap, not enforceMorphMap: enforcement is global and would reject other modules' unmapped morphs.
        Relation::morphMap([
            'merchant_user' => User::class,
        ]);

        $this->registerRateLimiters();
        $this->refuseConsentWithoutAScreen();

        // nwidart/laravel-modules discovers providers, not commands.
        if ($this->app->runningInConsole()) {
            $this->commands([
                IssueDemoTokenCommand::class,
                ProvisionBffClientCommand::class,
                SetDemoPasswordCommand::class,
            ]);
        }
    }

    private function registerRateLimiters(): void
    {
        // Every merchant behind the BFF shares one egress address, so an address budget would
        // throttle the whole tenant base together.
        RateLimiter::for('oauth-token', function (Request $request): Limit {
            $configured = config('passport.bff_client_id');

            return is_string($configured) && $configured !== '' && $request->input('client_id') === $configured
                ? Limit::perMinute(self::BFF_TOKEN_REQUESTS_PER_MINUTE)->by('oauth-token:'.$configured)
                : Limit::perMinute(self::ANONYMOUS_TOKEN_REQUESTS_PER_MINUTE)->by('oauth-token:'.$request->ip());
        });

        // Keyed by both: one address must not walk a password list across accounts, and one
        // account must not be lockable from an address its owner never uses.
        RateLimiter::for('merchant-login', fn (Request $request): Limit => Limit::perMinute(self::LOGIN_ATTEMPTS_PER_MINUTE)
            ->by(MerchantLoginController::normaliseEmail($request->string('email')->toString()).'|'.$request->ip()));
    }

    private static function sessionGuard(AuthFactory $auth): StatefulGuard
    {
        $guard = $auth->guard('merchant_web');

        if (! $guard instanceof StatefulGuard) {
            throw new RuntimeException('The merchant_web guard must be a session guard.');
        }

        return $guard;
    }

    private function refuseConsentWithoutAScreen(): void
    {
        // Passport leaves this contract unbound, so a client that does not skip consent would fail
        // as an unresolvable dependency instead of a refusal.
        Passport::authorizationView(fn (): Response => response(
            'This authorization server grants no consent screen. Only the registered first-party client may authorize.',
            403,
            ['Content-Type' => 'text/plain'],
        ));
    }
}
