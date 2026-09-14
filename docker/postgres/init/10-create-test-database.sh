#!/bin/sh
#
# Creates the separate PostgreSQL test database alongside the development one.
#
# The development environment specification requires a separate testing
# database. Both live in this single pinned engine, so development and test can
# never drift onto different PostgreSQL versions.
#
#   ledgerflow        development
#   ledgerflow_test   test suite target, reset freely by RefreshDatabase
#
# IMPORTANT: docker-entrypoint-initdb.d scripts run exactly once, when the data
# directory is first initialised. Editing this file afterwards does nothing
# until the volume is recreated. `make install` (slice 3) therefore performs
# the same creation idempotently, so this script is the fresh-volume path only.
#
# The name is fixed rather than derived from POSTGRES_DB: tests/bootstrap.php,
# the Makefile and the destructive-command guard recognise only ledgerflow_test.
set -eu

TEST_DB=ledgerflow_test

echo "init: ensuring test database ${TEST_DB} exists"

psql --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" \
     --set ON_ERROR_STOP=1 \
     --set test_db="$TEST_DB" <<-'SQL'
	SELECT format('CREATE DATABASE %I OWNER %I', :'test_db', current_user)
	WHERE NOT EXISTS (
	    SELECT FROM pg_database WHERE datname = :'test_db'
	)
	\gexec
SQL

echo "init: ${TEST_DB} ready"
