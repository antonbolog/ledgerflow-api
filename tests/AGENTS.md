# Test instructions

Applies to `tests/**` in addition to the repository root instructions. Module-local test suites
inherit their module's scoped instructions instead.

## Isolation

- The suite may mutate only `ledgerflow_test`. `tests/bootstrap.php`, `phpunit.xml`, and the live
  infrastructure contract jointly enforce this boundary.
- `tests/bootstrap.php` also forces `APP_ENV=testing`. Destructive Artisan database commands are
  refused for any other environment or target, so never probe them without a SQL-refusing sentinel.
- Framework defaults remain `QUEUE_CONNECTION=sync`, `CACHE_STORE=array`, and
  `SESSION_DRIVER=array`. Reach Redis only through an explicitly named test connection.
- Never flush a shared Redis database. Delete only the exact keys owned by the test.

## Evidence quality

- Prefer the narrowest test level that proves the behavior; use real PostgreSQL/Redis integration
  only for database, lock, transaction, queue, or transport semantics.
- Make controls non-vacuous and prove the failure path before relying on the green assertion.
- Avoid sleeps for concurrency or timing evidence when locks, barriers, injected clocks, or direct
  state observation can synchronize the test.
- Assert public behavior and durable contracts rather than private implementation shape unless an
  architecture rule is the behavior under test.
- Do not weaken existing boundary, isolation, or mutation probes to make a change pass.

Run tests through `make test`, `make test-unit`, `make test-integration`, or the full `make check`
contract.
The split CI commands must retain all four suites declared in `phpunit.xml`.
