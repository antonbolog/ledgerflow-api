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

/**
 * The Merchant module's registration point, named by module.json.
 *
 * It carries exactly two things: the container binding for the module's one
 * published contract, and the morph alias that keeps a class name out of the
 * database. Everything else the generator produced is deliberately gone.
 *
 * The generated provider extended Nwidart\Modules\Support\ModuleServiceProvider
 * and registered an EventServiceProvider and a RouteServiceProvider. The latter
 * was never generated, because config/modules.php switches that generator off,
 * so the file shipped referencing a class that does not exist. None of it is
 * wanted here either: modules ship no frontend, publish no config, and the first
 * module route arrives with the task that has an endpoint to serve.
 *
 * There is no loadMigrationsFrom() call. `modules.auto-discover.migrations` is
 * true, and LaravelModulesServiceProvider::registerMigrations() already walks
 * every enabled module and registers its migration path on the Migrator. Calling
 * it here as well would register the same path twice and, worse, would leave two
 * mechanisms that could disagree about which directory is authoritative.
 *
 * #[Override] is on register() and only on register(): the parent declares that
 * method and declares no boot(), where the attribute would be a fatal error.
 */
class MerchantServiceProvider extends ServiceProvider
{
    #[Override]
    public function register(): void
    {
        // scoped, not singleton: a request-lifetime binding that the container
        // clears between requests and queued jobs. bind() would behave the same
        // today because the object is stateless, but scoped states the lifetime
        // the guard behind it actually has.
        //
        // The guard name is passed here rather than defaulted in the
        // constructor, so the one place that decides it is the one place that
        // wires it.
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
        // The database stores this alias, never a class name. Without it,
        // personal_access_tokens.tokenable_type would hold a 60-character
        // namespace, and moving the class would become a data migration. That
        // class moved in this very task.
        //
        // morphMap, not enforceMorphMap: enforcing is a global switch, and
        // setting it from inside one module lays a trap for the first Payments
        // morph that has not been registered yet.
        Relation::morphMap([
            'merchant_user' => User::class,
        ]);
    }
}
