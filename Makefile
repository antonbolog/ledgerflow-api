# Written for GNU Make 3.81, as shipped by macOS: no .ONESHELL, $(file ...) or != assignment.

# Several recipes are ordered sequences, and a guard must never run concurrently with what it guards.
.NOTPARALLEL:

.DEFAULT_GOAL := help

DC     := docker compose
RUN    := $(DC) run --rm -T --no-deps app
RUN_DB := $(DC) run --rm -T app
EXEC   := $(DC) exec -T

export HOST_UID := $(shell id -u)
export HOST_GID := $(shell id -g)

# Hardcoded allowlist: editing .env must never widen the databases these targets may touch.
DEV_DB  := ledgerflow
TEST_DB := ledgerflow_test

# assert_local_db: refuse unless the effective target, DB_URL first, is pgsql on the compose postgres service.
# Runs through exec, never run, so the guard and the Artisan command observe the same container environment.
#   $(1) expected database name
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

.PHONY: help
help: ## Show the public command surface
	@echo "Ledgerflow — public command interface"
	@echo ""
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z0-9_-]+:.*?## /{printf "  %-16s %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@echo ""
	@echo "Destructive targets require explicit confirmation:"
	@echo "  make destroy      CONFIRM=destroy"
	@echo "  make demo-corrupt PAYMENT_ID=<uuid> CONFIRM=corrupt-projection"

# composer install and key:generate must run before up: the bind mount hides vendor/, and /up needs both.
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
	@echo "==> ensuring Passport keys"
	@$(RUN) sh -c '\
	  if [ -f storage/oauth-private.key ] && [ -f storage/oauth-public.key ]; then \
	    echo "  Passport keys present, preserved"; \
	  elif [ -f storage/oauth-private.key ] || [ -f storage/oauth-public.key ]; then \
	    echo "ERROR: only one of storage/oauth-private.key and storage/oauth-public.key exists." >&2; \
	    echo "       Refusing to overwrite it: replacing a key invalidates every issued access token." >&2; \
	    echo "       Fix: restore the missing key, or delete the remaining one deliberately." >&2; \
	    exit 1; \
	  else \
	    echo "  Passport keys absent, generating"; \
	    php artisan passport:keys --ansi; \
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

# stop, never down. --profile projection, because stop selects by active profile, not by what is running.
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

# The scheduler and Horizon start and stop together, after migrations; no target runs either alone.
.PHONY: projection-start
projection-start: db-migrate ## Start the opt-in scheduler and Horizon consumer together
	@echo "==> starting the projection pair (scheduler + horizon)"
	$(DC) --profile projection up -d --wait scheduler horizon

.PHONY: projection-stop
projection-stop: ## Stop the scheduler and Horizon consumer together
	@echo "==> stopping the projection pair"
	$(DC) --profile projection stop scheduler horizon

# Demo values are caller-supplied: read them only through quoted shell expansions, never as $(VAR) in recipe text.
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
demo-token: ## Print one one-hour Passport bearer token for a seeded fixture merchant (EMAIL=...)
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
	@token=$$($(EXEC) -e DEMO_EMAIL app sh -c 'php artisan merchant:demo-token "$$DEMO_EMAIL"' \
	  | tr -d '\r' | grep -E '^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$$' | tail -1); \
	  [ -n "$$token" ] || { \
	    echo "REFUSING: no token was minted." >&2; \
	    echo "  Run make db-seed if the fixture identities do not exist yet," >&2; \
	    echo "  and check that the app container is running." >&2; \
	    exit 1; }; \
	  echo "$$token"

# No -T: the password is read from a hidden prompt, which needs a terminal. It is never an
# argument or an environment variable, so nothing but that prompt can carry it.
.PHONY: demo-password
demo-password: ## Set a seeded fixture merchant's local password from a hidden prompt (EMAIL=...)
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
	@$(MAKE) --no-print-directory demo-guard
	$(DC) exec -e DEMO_EMAIL app sh -c 'php artisan merchant:demo-password "$$DEMO_EMAIL"'

# The redirect URI is exact-match and lives here, not in the command: the command is the same one
# ledgerflow-infra would run against a real environment with a real callback.
.PHONY: demo-bff-client
demo-bff-client: ## Register the local BFF OAuth client and print its secret once
	@$(MAKE) --no-print-directory demo-guard
	$(EXEC) app php artisan merchant:bff-client http://localhost:3000/auth/callback

# Non-fatal: non-zero exits are states this target exists to show, so the code is printed, not swallowed.
.PHONY: demo-status
demo-status: ## Show relay heartbeat and Horizon supervisor state (read-only)
	@echo "==> payments:outbox:status"
	@$(EXEC) app php artisan payments:outbox:status \
	  || echo "    exit $$? - unhealthy, or it could not report at all"
	@echo "==> horizon:status"
	@$(EXEC) app php artisan horizon:status \
	  || echo "    exit $$? - inactive, never run, or unreachable"

# Deletes only when exactly one row matches, decided in the same statement.
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

# Idempotent: the compose init script only runs on a fresh data directory.
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

.PHONY: db-seed
db-seed: ## Run the deterministic development seeders
	@echo "==> seeding $(DEV_DB)"
	$(call assert_local_db,$(DEV_DB))
	$(EXEC) app php artisan db:seed --force

# Check-only: quality must never rewrite source.
.PHONY: quality
quality: ## Run Composer, formatting, refactoring and static analysis checks (read-only)
	@echo "==> quality (read-only: no file is modified)"
	$(RUN) sh -c '\
	  composer validate --strict && \
	  vendor/bin/pint --test && \
	  vendor/bin/rector process --dry-run && \
	  vendor/bin/phpstan analyse --memory-limit=-1 --no-progress'

# Leaves postgres and redis running: they may already serve a development session.
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
