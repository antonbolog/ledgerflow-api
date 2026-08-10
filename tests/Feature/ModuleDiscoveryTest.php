<?php

declare(strict_types=1);

use Modules\Merchant\Providers\MerchantServiceProvider;
use Modules\Payments\Providers\PaymentsServiceProvider;
use Nwidart\Modules\Facades\Module;

/*
|--------------------------------------------------------------------------
| Module discovery
|--------------------------------------------------------------------------
|
| ModuleBoundaryTest reads the filesystem, so it passes whether or not the
| application can actually see a module. This one boots the framework and asks
| the module manager, which is the mechanism that decides whether a module's
| provider is registered and its migrations are discovered.
|
| The failure this catches is quiet and expensive: a module disabled in
| modules_statuses.json, or absent from the activator, still has all its files
| on disk and still passes every structural rule, while its migrations silently
| never run.
|
*/

it('discovers and enables every module through the module manager', function (string $module): void {
    expect(Module::has($module))->toBeTrue("Module [{$module}] is not discovered by the module manager.")
        ->and(Module::find($module)?->isEnabled())->toBeTrue("Module [{$module}] is discovered but not enabled.");
})->with(['Merchant', 'Payments']);

it('registers each module service provider with the application', function (): void {
    // Discovery is not registration. A module can be enabled while its provider
    // fails to load, and the symptom would appear slices later as a missing
    // binding or an unregistered migration path.
    $loaded = app()->getLoadedProviders();

    expect($loaded)->toHaveKey(MerchantServiceProvider::class)
        ->and($loaded)->toHaveKey(PaymentsServiceProvider::class);
});

it('registers every enabled module migration path on the migrator', function (): void {
    // The providers deliberately do not call loadMigrationsFrom():
    // modules.auto-discover.migrations is true and the package registers these
    // paths itself. This asserts the mechanism that was kept, so removing the
    // config flag cannot pass unnoticed.
    $paths = resolve('migrator')->paths();

    expect($paths)->toContain(base_path('Modules/Merchant/database/migrations'))
        ->and($paths)->toContain(base_path('Modules/Payments/database/migrations'));
});
