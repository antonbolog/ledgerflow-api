# Development Environment Specification

## Goal

A clean checkout must behave the same in PhpStorm, VS Code, Cursor, or a terminal. Docker and a
small Makefile interface define the development contract; an editor does not.

## Current state

**T1 is complete.** The container contract, Makefile interface, and tooling below are implemented
and verified from a clean checkout. `AGENTS.md` is canonical for exact target names, prerequisites,
effects, and evidence; this document states the requirements they satisfy.

Implemented in T1: the multi-stage `Dockerfile` (`base`/`vendor`/`dev`/`production`/`web`),
`compose.yaml` with health checks and persistent volumes, the separate `ledgerflow_test` database,
the twelve-target Makefile, Pest on PostgreSQL with a three-layer database guard, Pint, Rector,
PHPStan level 10, and CI.

**All application validation runs through public Make targets** — CI installs from a clean checkout
with `make install` and validates with the single `make check` contract, and contains no Composer,
Pest, Pint, Rector, PHPStan, or Artisan invocation of its own. CI *additionally* performs four
explicitly documented classes of assertion, none of which validate application code:

- **guard assertions** — `destroy`, `db-reset`, `db-migrate`, and `db-seed` must refuse, each with
  its documented refusal message;
- **isolation assertion** — a marker row proves `make check` leaves the development database
  untouched while the suite resets `ledgerflow_test`;
- **image-contract builds** — `docker build --target production` and `--target web`, because
  Compose only ever builds the `dev` target;
- **failure diagnostics** — `docker compose ps` and `logs`, on failure only.

These are contract assertions about the Makefile and the image, not a second validation path.

The entrypoint ends in `exec "$@"` with `CMD ["php-fpm"]`, so Horizon, the scheduler/relay, Reverb,
and future SQS workers reuse the same image with a `command:` override and no image change. That
invariant is what makes "reuse the application image for future worker and scheduler services"
below true rather than aspirational.

## Container contract

- Own a custom production-oriented application `Dockerfile`; do not use Laravel Sail.
- Use `compose.yaml` for local application, PostgreSQL, and Redis services.
- Reuse the application image for future worker and scheduler services.
- Add health checks and persistent local volumes.
- Provide a separate PostgreSQL testing database.
- Track shared Docker configuration and `.env.example`; never track local secrets or runtime data.
- Keep AWS services, Terraform, and deployment credentials out of this repository.

The production image may later be specialized into runtime targets, but local development and AWS
workers must execute the same application artifact and dependency set.

## Intended Makefile interface

The first milestone must establish a small public surface in these directions:

| Direction | Intended targets | Contract |
|---|---|---|
| Setup | `install` | Build/configure the project from a clean checkout |
| Lifecycle | `start`, `stop` | Start and stop local services without deleting data |
| Destructive lifecycle | `destroy` | Remove generated containers/volumes only after explicit confirmation |
| Validation | `test`, `quality`, `check` | Run supported tests, quality tools, and the CI-equivalent full check |
| Database | `db-migrate`, `db-seed` | Apply schema and deterministic development fixtures |
| Destructive database | `db-reset` | Reset local/test data only after explicit confirmation |

T1 may adjust exact target names when implementation reveals a better contract. Afterward,
`AGENTS.md` must document prerequisites, effects, and expected evidence. CI must call the same
public targets instead of duplicating hidden command sequences.

## Tooling requirements

This is the Milestone 1–3 inventory, split by the task that installs each item. T1 installs only
what it validates.

**Installed and verified by T1**

- PHP **8.4** pinned (`php:8.4.24-fpm-alpine`); `composer.json` requires `^8.4.1`, the minimum the
  locked graph resolves against. Laravel 13.
- Pinned by project configuration: `postgres:18.4-alpine`, `redis:8.10.0-alpine`,
  `nginx:1.30.4-alpine`, `composer:2.8.12`, phpredis 6.3.0.
- Pest 5 and `pest-plugin-arch` 5. Both require PHP `^8.4`, which is what forces the pin.
- Larastan 3 (level 10, no baseline), Pint 1, Rector 2 with `driftingly/rector-laravel`.

**Installed by T2**

- `nwidart/laravel-modules` 13 — module registration. Pulls
  `wikimedia/composer-merge-plugin`, which needs an `allow-plugins` entry.
- Laravel Sanctum 4 — merchant-user identity.
- `spatie/laravel-data` 4 — the first DTO arrives with the first module.

**Installed by T3/T4**

- `spatie/laravel-event-sourcing` 7.15 — its API behavior is the compatibility spike's subject.

`laravel/pail` was removed from the skeleton's dependencies: it has no consumer, since the Makefile
owns the command surface and no `logs` target exists.

No dependency is added only because it is fashionable. `ahmedsaed/laravel-ddd` is rejected; its
folder convention may be reproduced manually after the decision is recorded as an ADR.

## Editor and ignore policy

`.gitignore` excludes IDE metadata such as `.idea/`, `.vscode/`, `.cursor/`, `.codex/`, `.fleet/`,
and local history, plus local environment files, credentials, dependencies, OS metadata, logs,
coverage, and generated runtime data.

Shared editor configuration is limited to `.editorconfig`, adopted as an explicit project decision
in T1 so that PhpStorm, VS Code, and Cursor apply the same whitespace and encoding rules to one
checkout. Any further shared editor configuration requires its own explicit decision.

## Acceptance evidence

From a clean checkout with Git, Docker, and Docker Compose:

1. The documented installation path succeeds. **(T1)**
2. Application, PostgreSQL, and Redis health checks become healthy. **(T1)**
3. The seeding command runs successfully and is repeatable, establishing the deterministic
   `db-seed` contract. **(T1)** Two deterministic merchants and users are seeded through that same
   command. **(T2 — merchant-user identity)**
4. Unit, integration, architecture, static-analysis, formatting, and full-check contracts run only
   through documented project targets. **(T1)**
5. Destructive targets refuse to run without explicit confirmation and cannot remove source files.
   **(T1)**

This list is the Milestone 1 exit criterion, not the T1 exit criterion. T1 establishes the command
contracts; T2 supplies the deterministic identities that item 3's second clause requires. T1 must
not be reviewed against the T2 clause.

All T1 items above are verified. Validation must not be invented ad hoc: `check` is the only
CI-equivalent entry point, and CI calls it rather than reproducing its steps.

## Known deferred concerns

Recorded so they are not rediscovered as surprises:

- **`public/storage` is not served.** The local nginx container mounts only `./public`, so a
  `storage:link` symlink points outside its filesystem and user-uploaded files would 404. Nothing in
  T1 uses it; the first feature that does needs a narrow second mount or object storage.
- **The Pest suites are not statically analysed.** PHPStan cannot see through Pest's fluent DSL, so
  only `tests/bootstrap.php` is analysed from `tests/`. The suites are verified by running them.
- **PHPStan level 10 is untested against real domain code** and will likely need lowering when T2
  lands generics-heavy repositories. Lower it explicitly and record why; do not generate a baseline.
- **Editing `.env` requires `make start`** before the app container observes the change. The
  database guard reads the running container, so a stale container is refused rather than silently
  used.
- **`make test` leaves `postgres` and `redis` running.**
- **The `$_SERVER` env-precedence trap** that let the suite reach the development database is fixed
  in `tests/bootstrap.php`, but the fix is not itself covered by a test.
- **CI has no Docker layer caching**, deliberately: `docker compose build` cannot use the Actions
  cache backend without a buildx bake setup that local development would not share.

## Related

- [Roadmap](../../roadmap.md)
- [Runtime contract](../../operations/runtime-contract.md)
- [Resilience test plan](../../testing/payments-resilience.md)
