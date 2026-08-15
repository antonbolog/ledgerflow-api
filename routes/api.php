<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use Modules\Payments\Http\Controllers\InitiatePaymentController;

/*
|--------------------------------------------------------------------------
| API routes
|--------------------------------------------------------------------------
|
| The first API route in the project.
|
| IT LIVES HERE RATHER THAN INSIDE THE PAYMENTS MODULE because
| tests/Architecture/ModuleBoundaryTest.php asserts that no module carries a
| routes directory, and moving that boundary is not a decision this endpoint
| needs. Referencing a module's Http layer from the root is permitted: only
| Domain and Infrastructure are closed to the outside.
|
| bootstrap/app.php registers this file with the `api` prefix and middleware
| group. That is the part of `install:api` that finally has a consumer; the rest
| of it — the example route and its scaffolding — is still not run.
|
| The api middleware group is Laravel's default (SubstituteBindings only). No
| throttling: the initiation specification names rate limiting solely to say it
| cannot provide idempotency, so it is not a T6 deliverable.
|
*/

Route::middleware('auth:sanctum')->group(function (): void {
    Route::post('/payments', InitiatePaymentController::class)->name('payments.initiate');
});
