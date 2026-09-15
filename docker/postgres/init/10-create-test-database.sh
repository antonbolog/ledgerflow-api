#!/bin/sh
# Runs only on a fresh data directory; make db-ensure-test repeats this idempotently for existing volumes.
# The name is fixed: tests/bootstrap.php, the Makefile and the destructive-command guard recognise only ledgerflow_test.
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
