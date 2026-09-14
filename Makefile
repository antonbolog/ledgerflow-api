# Ledgerflow — public command interface.
#
# This Makefile is the sole interface for three categories of work:
#
#   environment lifecycle   install  start  stop  destroy
#   database lifecycle      db-migrate  db-seed
#   projection runtime      projection-start  projection-stop
#   validation              quality  test-unit  test-integration  test  check  ci
#   images                  build-runtime  build-nginx
#
# CI derives all validation from these targets. Ad-hoc `artisan`, `tinker` and
# `psql` access is ordinary developer work and is not a violation of that rule.
#
# Written for GNU Make 3.81, the version macOS ships. No .ONESHELL, no
# $(file ...), no != assignment: CI runners have 4.x, so the older one is the
# binding constraint.

# .NOTPARALLEL because several recipes are ordered sequences and a guard must
# never run concurrently with the recipe it guards.
.NOTPARALLEL:

.DEFAULT_GOAL := help

DC     := docker compose
RUN    := $(DC) run --rm -T --no-deps app
RUN_DB := $(DC) run --rm -T app
# Service name is supplied per call, because compose options must precede it.
EXEC   := $(DC) exec -T

# Exported so compose picks them up as build args for the dev stage on EVERY
# build path, not only an explicit `make install`.
export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

# Hardcoded, deliberately. These are the only databases any target in this file
# may touch. They are not read from .env: the whole point of the allowlist is
# that editing .env cannot widen it.
DEV_DB  := ledgerflow
TEST_DB := ledgerflow_test

# ---------------------------------------------------------------------------
# assert_local_db - refuse to mutate anything that is not the local project
# development database.
#
# Resolves the EFFECTIVE target the way Laravel does, with DB_URL taking
# precedence over DB_HOST/DB_DATABASE, then requires all of:
#
#   connection  pgsql
#   host        the compose `postgres` service
#   database    exactly the expected name
#
# It runs through `exec`, in the SAME running container the Artisan command will
# use, and never through `run`. That distinction is the whole guarantee: `run`
# builds a fresh container and re-reads .env from disk, while `exec` inherits
# the environment the running container was created with. Guarding with `run`
# and mutating with `exec` reads two different environments, so editing .env
# after the stack started would let the guard approve a target the migration
# never actually uses. Both halves must observe one environment.
#
#   $(1) expected database name
# ---------------------------------------------------------------------------
define assert_local_db
	@$(EXEC) app php -r '\
	  $$connection = getenv("DB_CONNECTION") ?: ""; \
	  $$url = getenv("DB_URL") ?: ""; \
	  if ($$url !== "") { \
	    $$parts = parse_url($$url); \
	    $$host = $$parts["host"] ?? ""; \
	    $$db = isset($$parts["path"]) ? ltrim($$parts["path"], "/") : ""; \
	    $$via = "DB_URL"; \
	  } else { \
	    $$host = getenv("DB_HOST") ?: ""; \
	    $$db = getenv("DB_DATABASE") ?: ""; \
	    $$via = "DB_HOST/DB_DATABASE"; \
	  } \
	  $$expected = "$(1)"; \
	  if ($$connection !== "pgsql" || $$host !== "postgres" || $$db !== $$expected) { \
	    fwrite(STDERR, "REFUSING: this target may only touch the local project database.\n"); \
	    fwrite(STDERR, "  resolved via $$via: connection=$$connection host=$$host database=$$db\n"); \
	    fwrite(STDERR, "  required: connection=pgsql host=postgres database=$$expected\n"); \
	    fwrite(STDERR, "  Fix DB_CONNECTION / DB_URL / DB_HOST / DB_DATABASE in .env, then run make start.\n"); \
	    exit(1); \
	  } \
	  fwrite(STDOUT, "  target ok: $$host/$$db\n"); \
	'
endef

# ---------------------------------------------------------------------------
# Help
# ---------------------------------------------------------------------------

.PHONY: help
help: ## Show the public command surface
	@echo "Ledgerflow — public command interface"
	@echo ""
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_-]+:.*?## /{printf "  %-16s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@echo ""
	@echo "Destructive targets require explicit confirmation:"
	@echo "  make destroy      CONFIRM=destroy"
	@echo "  make demo-corrupt PAYMENT_ID=<uuid> CONFIRM=corrupt-projection"

# ---------------------------------------------------------------------------
# Environment lifecycle
# ---------------------------------------------------------------------------

# The order below is load-bearing:
#
#   composer install runs BEFORE `up` because the bind mount hides the image's
#   vendor/, and nginx's /up health check cannot pass without it. Moved below
#   `up`, a clean checkout deadlocks waiting for a container that cannot become
#   healthy until a later step.
#
#   key:generate also runs BEFORE `up`: booting with an empty APP_KEY risks a
#   500 from the health route, and `up --wait` would then never return.
.PHONY: install
install: ## Build and start the stack from a clean checkout
	@echo "==> ensuring .env exists"
	@[ -f .env ] || cp .env.example .env
	@echo "==> building images (HOST_UID=$(HOST_UID) HOST_GID=$(HOST_GID))"
	$(DC) build
	@echo "==> installing dependencies"
	$(RUN) composer install --no-interaction
	@echo "==> ensuring APP_KEY"
	@$(RUN) sh -c '\
	  if ! grep -q "^APP_KEY=" .env; then \
	    echo "ERROR: .env has no APP_KEY entry at all." >&2; \
	    echo "       Refusing to guess: an application booted without APP_KEY fails" >&2; \
	    echo "       at the first encrypted cookie or session, far from the cause." >&2; \
	    echo "       Fix: add an empty APP_KEY= line to .env, or re-copy .env.example." >&2; \
	    exit 1; \
	  elif grep -q "^APP_KEY=$$" .env; then \
	    echo "  APP_KEY is empty, generating"; \
	    php artisan key:generate --ansi; \
	  else \
	    echo "  APP_KEY present, preserved"; \
	  fi'
	@echo "==> starting services"
	$(DC) up -d --wait
	@$(MAKE) --no-print-directory db-ensure-test
	@$(MAKE) --no-print-directory db-migrate
	@echo "==> stack"
	$(DC) ps

.PHONY: start
start: ## Start the local services
	$(DC) up -d --wait
	$(DC) ps

# `stop`, never `down`: containers halt, volumes and their data survive.
#
# --profile projection, even here where the profile is never STARTED by this
# file's own targets: `stop` selects containers by currently active profile, not
# by what happens to be running, so the scheduler and Horizon an operator started
# with `projection-start` would otherwise survive a plain `stop` untouched.
.PHONY: stop
stop: ## Stop the local services, keeping all data (also reaches the opt-in scheduler and Horizon)
	$(DC) --profile projection stop

.PHONY: destroy
destroy: ## Remove containers, networks and volumes (CONFIRM=destroy)
	@if [ "$(CONFIRM)" != "destroy" ]; then \
	  echo "REFUSING: destroy removes this project's containers, networks and volumes."; \
	  echo "  It deletes the $(DEV_DB) and $(TEST_DB) data permanently."; \
	  echo "  It cannot touch files in your working tree."; \
	  echo "  Re-run with: make destroy CONFIRM=destroy"; \
	  exit 1; \
	fi
	$(DC) --profile projection down --volumes --remove-orphans

# ---------------------------------------------------------------------------
# Opt-in projection pair: scheduler AND Horizon, together or not at all
#
# Neither `install` nor `start` ever passes --profile projection, so a clean
# install's four default services never change.
#
# THERE IS DELIBERATELY NO TARGET THAT STARTS ONE WITHOUT THE OTHER. The relay
# publishes into `payments-projection` and Horizon is the only thing that drains
# it, so a scheduler running alone produces a growing backlog that reads as a
# consumer fault rather than as a missing process. Both names appear on one
# command line so the pair is what an operator can ask for.
#
# STARTED ONLY AFTER MIGRATIONS. `projection-start` depends on db-migrate: the
# consumer's first act is to lock a `payment_outbox` row and write
# `payment_projector_receipts`, and starting workers against a schema that lacks
# those tables produces a burst of failures that look like a projection defect.
# ---------------------------------------------------------------------------

.PHONY: projection-start
projection-start: db-migrate ## Start the opt-in scheduler and Horizon consumer together
	@echo "==> starting the projection pair (scheduler + horizon)"
	$(DC) --profile projection up -d --wait scheduler horizon

.PHONY: projection-stop
projection-stop: ## Stop the scheduler and Horizon consumer together
	@echo "==> stopping the projection pair"
	$(DC) --profile projection stop scheduler horizon

# ---------------------------------------------------------------------------
# Demo support - local development only, for docs/operations/five-minute-demo.md.
#
# EMAIL, PAYMENT_ID and CONFIRM are caller-supplied: they are exported into the recipe
# environment and read only through quoted shell expansions, never interpolated as
# $(EMAIL) into recipe text, where a quote in the value would close it and run the rest.
# ---------------------------------------------------------------------------

# Hardcoded like DEV_DB and TEST_DB: an allowlist another file can widen is not one.
DEMO_EMAILS := owner@northwind.test owner@atlantic.test
EMAIL       ?= owner@northwind.test

export DEMO_EMAILS
export DEMO_EMAIL      := $(EMAIL)
export DEMO_PAYMENT_ID := $(PAYMENT_ID)
export DEMO_CONFIRM    := $(CONFIRM)

.PHONY: demo-guard
demo-guard:
	$(call assert_local_db,$(DEV_DB))

# Stdout carries the token and nothing else, so `TOKEN=$$(make demo-token)` works.
.PHONY: demo-token
demo-token: ## Print one Sanctum token for a seeded fixture merchant (EMAIL=...)
	@allowed=0; \
	  for e in $$DEMO_EMAILS; do \
	    [ "$$e" = "$$DEMO_EMAIL" ] && allowed=1; \
	  done; \
	  [ "$$allowed" = 1 ] || { \
	    echo "REFUSING: EMAIL must name a seeded fixture identity." >&2; \
	    echo "  allowed: $$DEMO_EMAILS" >&2; \
	    echo "  given:   $$DEMO_EMAIL" >&2; \
	    echo "  Run make db-seed if these identities do not exist yet." >&2; \
	    exit 1; }
	@$(MAKE) --no-print-directory demo-guard >&2
	@token=$$($(EXEC) -e DEMO_EMAIL app php artisan tinker --execute \
	  'echo \Modules\Merchant\Infrastructure\Persistence\Eloquent\User::query()->where("email", getenv("DEMO_EMAIL"))->firstOrFail()->createToken("ledgerflow-demo")->plainTextToken, PHP_EOL;' \
	  | tr -d '\r' | grep -oE '[0-9]+\|[A-Za-z0-9]{40,}' | tail -1); \
	  [ -n "$$token" ] || { \
	    echo "REFUSING: no token was minted." >&2; \
	    echo "  Run make db-seed if the fixture identities do not exist yet," >&2; \
	    echo "  and check that the app container is running." >&2; \
	    exit 1; }; \
	  echo "$$token"

# Non-fatal: both exit non-zero for states this target exists to show, and the codes
# cannot separate those from a stopped container, so the code is printed not swallowed.
.PHONY: demo-status
demo-status: ## Show relay heartbeat and Horizon supervisor state (read-only)
	@echo "==> payments:outbox:status"
	@$(EXEC) app php artisan payments:outbox:status \
	  || echo "    exit $$? - unhealthy, or it could not report at all"
	@echo "==> horizon:status"
	@$(EXEC) app php artisan horizon:status \
	  || echo "    exit $$? - inactive, never run, or unreachable"

# Deletes only while exactly one row matches, in one snapshot, so a zero or multiple
# match removes nothing rather than being detected afterwards.
.PHONY: demo-corrupt
demo-corrupt: ## Delete one named payment_details row for the demo (PAYMENT_ID=... CONFIRM=corrupt-projection)
	@[ -n "$$DEMO_PAYMENT_ID" ] || { \
	  echo "REFUSING: demo-corrupt never picks a payment for you."; \
	  echo "  Name the payment from the initiation response:"; \
	  echo "  make demo-corrupt PAYMENT_ID=<uuid> CONFIRM=corrupt-projection"; \
	  exit 1; }
	@printf '%s' "$$DEMO_PAYMENT_ID" | grep -Eq '^[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}$$' \
	  || { echo "REFUSING: PAYMENT_ID is not a canonical UUID."; exit 1; }
	@echo "==> demo-corrupt would delete one read-model row:"
	@echo "      host:       postgres (compose service)"
	@echo "      database:   $(DEV_DB)"
	@echo "      table:      payment_details"
	@echo "      payment_id: $$DEMO_PAYMENT_ID"
	@[ "$$DEMO_CONFIRM" = "corrupt-projection" ] || { \
	  echo "REFUSING: demo-corrupt breaks local development data on purpose."; \
	  echo "  It removes one derived row and touches no canonical history."; \
	  echo "  Re-run with: make demo-corrupt PAYMENT_ID=$$DEMO_PAYMENT_ID CONFIRM=corrupt-projection"; \
	  exit 1; }
	@$(MAKE) --no-print-directory demo-guard
	@$(EXEC) -e DEMO_PAYMENT_ID postgres sh -c '\
	  counts=$$(echo "with target as ( \
	      select payment_id from payment_details where payment_id = :'"'"'pid'"'"' \
	    ), deleted as ( \
	      delete from payment_details \
	      where payment_id = :'"'"'pid'"'"' and (select count(*) from target) = 1 \
	      returning 1 \
	    ) select (select count(*) from target), (select count(*) from deleted);" \
	    | psql -U "$$POSTGRES_USER" -d $(DEV_DB) -v ON_ERROR_STOP=1 -qAt -F " " \
	           -v pid="$$DEMO_PAYMENT_ID") || exit 1; \
	  set -- $$counts; \
	  if [ "$$1" != "1" ] || [ "$$2" != "1" ]; then \
	    echo "REFUSING: expected exactly one matching payment_details row, matched $$1, removed $$2." >&2; \
	    echo "  Nothing was deleted. An unprojected or already corrupted payment matches nothing." >&2; \
	    exit 1; \
	  fi; \
	  echo "  deleted 1 payment_details row; canonical history is untouched"'

# ---------------------------------------------------------------------------
# Database lifecycle
# ---------------------------------------------------------------------------

# The compose init script runs only when the PostgreSQL data directory is first
# initialised, so a volume created before that script existed (or before it was
# last edited) will not have the test database. This step is idempotent: safe on
# every run, and it repairs an already-initialised volume without destroying it.
.PHONY: db-ensure-test
db-ensure-test: ## Create the test database if it is missing
	@echo "==> ensuring $(TEST_DB) exists"
	@$(EXEC) postgres sh -c '\
	  psql -U "$$POSTGRES_USER" -d "$$POSTGRES_DB" -tAc \
	    "SELECT 1 FROM pg_database WHERE datname = '"'"'$(TEST_DB)'"'"'" | grep -q 1 \
	  && echo "  $(TEST_DB) present" \
	  || { createdb -U "$$POSTGRES_USER" -O "$$POSTGRES_USER" "$(TEST_DB)" \
	       && echo "  $(TEST_DB) created"; }'

.PHONY: db-migrate
db-migrate: ## Apply migrations to the development database
	@echo "==> migrating $(DEV_DB)"
	$(call assert_local_db,$(DEV_DB))
	$(EXEC) app php artisan migrate --force

# The seeder is deterministic and repeatable; tests own collision and rollback
# evidence while this target owns the development command contract.
.PHONY: db-seed
db-seed: ## Run the deterministic development seeders
	@echo "==> seeding $(DEV_DB)"
	$(call assert_local_db,$(DEV_DB))
	$(EXEC) app php artisan db:seed --force

# ---------------------------------------------------------------------------
# Validation
#
# Non-destructive by contract: `quality` only reads files, and `test` mutates
# exactly one thing - the isolated ledgerflow_test database, which the suite
# truncates and re-migrates by design. Neither touches source files or the
# development database.
#
# Both run from a stopped stack. `quality` needs no services at all; `test`
# starts postgres and redis itself and leaves them running.
# ---------------------------------------------------------------------------

# Every PHP tool here only reads files, so this runs in a throwaway container
# with no service dependencies and works with the stack stopped. All four run in ONE
# container, chained with && so the first failure aborts: four separate `run`
# invocations would pay four container starts on a target used constantly.
#
# Every tool is in check-only mode. `quality` must never rewrite source: Pint
# uses --test, Rector uses --dry-run. The one-time write pass that normalised
# the skeleton was a separate, deliberate act, not something a validation
# target performs.
.PHONY: quality
quality: ## Run Composer, formatting, refactoring and static analysis checks (read-only)
	@echo "==> quality (read-only: no file is modified)"
	$(RUN) sh -c '\
	  composer validate --strict && \
	  vendor/bin/pint --test && \
	  vendor/bin/rector process --dry-run && \
	  vendor/bin/phpstan analyse --memory-limit=-1 --no-progress'

# Works from a completely stopped stack.
#
# db-ensure-test talks to a running postgres via `exec`, so the services it
# needs are started and waited for first. Only postgres and redis are started —
# not web, and not a long-lived app container — because the suite reaches the
# framework through its own one-off container.
#
# Pest runs in that one-off container rather than through `exec`: on a cold
# start there is no app container to exec into.
#
# SIDE EFFECT, by design: this target LEAVES postgres and redis running. It has
# no way to know whether they were already up for a development session, and
# stopping services someone else is using would be worse than leaving two
# containers idle. Use `make stop` to bring everything down.
.PHONY: test
test: ## Run the Pest suite against the isolated test database (starts postgres+redis)
	@echo "==> test (mutates $(TEST_DB) only; leaves postgres and redis running)"
	$(DC) up -d --wait postgres redis
	$(MAKE) --no-print-directory db-ensure-test
	$(RUN_DB) vendor/bin/pest --colors=always

.PHONY: check
check: ## Run normal local validation: quality then test
	$(MAKE) --no-print-directory quality
	$(MAKE) --no-print-directory test

.PHONY: test-unit
test-unit: ## Run the Unit suite without starting database services
	$(RUN) vendor/bin/pest --testsuite=Unit --colors=always

.PHONY: test-integration
test-integration: ## Run Integration, Feature and Architecture suites against the test database
	$(DC) up -d --wait postgres redis
	$(MAKE) --no-print-directory db-ensure-test
	$(RUN_DB) vendor/bin/pest --testsuite=Integration,Feature,Architecture --colors=always

.PHONY: build-runtime
build-runtime: ## Build the Laravel production runtime image without publishing
	docker build --target production -t ledgerflow-api:ci .

.PHONY: build-nginx
build-nginx: ## Build the Laravel Nginx image without publishing
	docker build --target web -t ledgerflow-web:ci .

.PHONY: ci
ci: ## Run quality, unit, integration and both image builds after install
	$(MAKE) --no-print-directory quality
	$(MAKE) --no-print-directory test-unit
	$(MAKE) --no-print-directory test-integration
	$(MAKE) --no-print-directory build-runtime
	$(MAKE) --no-print-directory build-nginx
