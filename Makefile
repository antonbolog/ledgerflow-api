# Ledgerflow — public command interface.
#
# This Makefile is the sole interface for three categories of work:
#
#   environment lifecycle   install  start  stop  destroy
#   database lifecycle      db-migrate  db-seed  db-reset
#   validation              quality  test  check
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
# assert_local_db — refuse to mutate anything that is not a local project
# database.
#
# Resolves the EFFECTIVE target the way Laravel does, with DB_URL taking
# precedence over DB_HOST/DB_DATABASE, then requires both:
#
#   host     in the compose-local set
#   database exactly equal to the expected role for this target
#
# Exact role mapping, not set membership: a .env that swaps the development and
# test names, or points both at the same database, is refused too.
#
# It runs through `exec`, in the SAME running container the Artisan command will
# use, and never through `run`. That distinction is the whole guarantee: `run`
# builds a fresh container and re-reads .env from disk, while `exec` inherits
# the environment the running container was created with. Guarding with `run`
# and mutating with `exec` reads two different environments, so editing .env
# after the stack started would let the guard approve a target the migration
# never actually uses. Both halves must observe one environment.
#
# The same $(2) options are passed to the guard and to the mutation, so the test
# database override is checked exactly as it is applied.
#
#   $(1) expected database name
#   $(2) extra docker options (e.g. -e DB_DATABASE=... for the test target)
# ---------------------------------------------------------------------------
define assert_local_db
	@$(EXEC) $(2) app php -r '\
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
	  $$allowedHosts = ["postgres", "127.0.0.1", "localhost", "::1"]; \
	  $$expected = "$(1)"; \
	  if (!in_array($$host, $$allowedHosts, true) || $$db !== $$expected) { \
	    fwrite(STDERR, "REFUSING: this target may only touch local project databases.\n"); \
	    fwrite(STDERR, "  resolved via $$via: host=$$host database=$$db\n"); \
	    fwrite(STDERR, "  allowed host:      " . implode(", ", $$allowedHosts) . "\n"); \
	    fwrite(STDERR, "  expected database: $$expected\n"); \
	    fwrite(STDERR, "  Fix DB_URL / DB_HOST / DB_DATABASE in .env, or run this against the right target.\n"); \
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
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_-]+:.*?## /{printf "  %-12s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@echo ""
	@echo "Destructive targets require explicit confirmation:"
	@echo "  make destroy  CONFIRM=destroy"
	@echo "  make db-reset CONFIRM=db-reset"

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
# --profile relay, even here where the profile is never STARTED by this file's
# own targets: `stop` selects containers by currently active profile, not by
# what happens to be running, so a scheduler an operator started by hand with
# `relay-start` would otherwise survive a plain `stop` untouched.
.PHONY: stop
stop: ## Stop the local services, keeping all data (also reaches the opt-in scheduler, if running)
	$(DC) --profile relay stop

.PHONY: destroy
destroy: ## Remove containers, networks and volumes (CONFIRM=destroy)
	@if [ "$(CONFIRM)" != "destroy" ]; then \
	  echo "REFUSING: destroy removes this project's containers, networks and volumes."; \
	  echo "  It deletes the $(DEV_DB) and $(TEST_DB) data permanently."; \
	  echo "  It cannot touch files in your working tree."; \
	  echo "  Re-run with: make destroy CONFIRM=destroy"; \
	  exit 1; \
	fi
	$(DC) --profile relay down --volumes --remove-orphans

# ---------------------------------------------------------------------------
# Opt-in local scheduler
#
# Disposable evidence only. Neither `install` nor `start` ever passes
# --profile relay, so a clean install's four default services never change;
# these two targets exist so an operator — or T7's own disposable Compose
# measurement — can add and remove the scheduler without touching the rest of
# the stack. T8 owns the production-like activation that starts this
# alongside its Horizon-supervised consumer.
# ---------------------------------------------------------------------------

.PHONY: relay-start
relay-start: ## Start the opt-in scheduler service (disposable evidence only)
	$(DC) --profile relay up -d --wait scheduler

.PHONY: relay-stop
relay-stop: ## Stop the opt-in scheduler service, leaving the rest of the stack running
	$(DC) --profile relay stop scheduler

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
	$(call assert_local_db,$(DEV_DB),)
	$(EXEC) app php artisan migrate --force

# T1 establishes only the command contract: this must run, exit zero and be
# repeatable. DatabaseSeeder is intentionally empty; T2 owns merchant-user
# identity and the deterministic merchants and users.
.PHONY: db-seed
db-seed: ## Run the deterministic development seeders
	@echo "==> seeding $(DEV_DB)"
	$(call assert_local_db,$(DEV_DB),)
	$(EXEC) app php artisan db:seed --force

.PHONY: db-reset
db-reset: ## Drop and rebuild local and test data (CONFIRM=db-reset)
	@echo "==> db-reset would rebuild, from scratch:"
	@echo "      host:      postgres (compose service)"
	@echo "      databases: $(DEV_DB), $(TEST_DB)"
	@if [ "$(CONFIRM)" != "db-reset" ]; then \
	  echo "REFUSING: db-reset drops every table in the databases listed above."; \
	  echo "  It cannot touch any other database, and it cannot touch files."; \
	  echo "  Re-run with: make db-reset CONFIRM=db-reset"; \
	  exit 1; \
	fi
	@$(MAKE) --no-print-directory db-ensure-test
	@echo "==> resetting $(DEV_DB)"
	$(call assert_local_db,$(DEV_DB),)
	$(EXEC) app php artisan migrate:fresh --force
	@echo "==> resetting $(TEST_DB)"
	$(call assert_local_db,$(TEST_DB),-e DB_DATABASE=$(TEST_DB))
	$(EXEC) -e DB_DATABASE=$(TEST_DB) app php artisan migrate:fresh --force
	@$(MAKE) --no-print-directory db-seed

# ---------------------------------------------------------------------------
# Validation
#
# `check` is the single CI-equivalent entry point. CI runs this and nothing
# else; it must never need a command sequence that does not exist here.
#
# Non-destructive by contract: `quality` only reads files, and `test` mutates
# exactly one thing — the isolated ledgerflow_test database, which the suite
# truncates and re-migrates by design. Neither touches source files or the
# development database.
#
# Both run from a stopped stack. `quality` needs no services at all; `test`
# starts postgres and redis itself and leaves them running.
# ---------------------------------------------------------------------------

# Every tool here only reads files, so this runs in a throwaway container with
# no service dependencies and works with the stack stopped. All four run in ONE
# container, chained with && so the first failure aborts: four separate `run`
# invocations would pay four container starts on a target used constantly.
#
# Every tool is in check-only mode. `quality` must never rewrite source: Pint
# uses --test, Rector uses --dry-run. The one-time write pass that normalised
# the skeleton was a separate, deliberate act, not something a validation
# target performs.
.PHONY: quality
quality: ## Run formatting, refactoring and static analysis checks (read-only)
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
check: ## Full CI-equivalent validation: quality then test
	$(MAKE) --no-print-directory quality
	$(MAKE) --no-print-directory test
