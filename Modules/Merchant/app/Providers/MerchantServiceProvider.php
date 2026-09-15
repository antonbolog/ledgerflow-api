<?php

declare(strict_types=1);

namespace Modules\Merchant\Providers;

use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\ServiceProvider;
use Modules\Merchant\Application\Identity\MerchantContext;
use Modules\Merchant\Infrastructure\Identity\AuthenticatedMerchantContext;
use Modules\Merchant\Infrastructure\Persistence\Eloquent\User;
use Override;

class MerchantServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        $this->app->scoped(
            MerchantContext::class,
            fn (Application $app): AuthenticatedMerchantContext => new AuthenticatedMerchantContext(
                $app->make(AuthFactory::class),
                'sanctum',
            ),
        );
    }

    public function boot(): void
    {
        // The alias is stored in personal_access_tokens.tokenable_type; renaming it needs a data migration.
        // morphMap, not enforceMorphMap: enforcement is global and would reject other modules' unmapped morphs.
        Relation::morphMap([
            'merchant_user' => User::class,
        ]);
    }
}
