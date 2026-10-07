#!/bin/sh
# Runs once when the PostgreSQL container initialises an empty data directory.
# Creates the application role WITHOUT superuser/bypassrls (so row-level security applies)
# and the application + test databases owned by it.
set -eu

psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" <<SQL
CREATE ROLE ricemill LOGIN PASSWORD '${RICEMILL_DB_PASSWORD}' NOSUPERUSER NOCREATEDB NOCREATEROLE NOBYPASSRLS;
CREATE DATABASE ricemill OWNER ricemill ENCODING 'UTF8';
CREATE DATABASE ricemill_test OWNER ricemill ENCODING 'UTF8';
SQL

for db in ricemill ricemill_test; do
  psql -v ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$db" \
    -c 'CREATE EXTENSION IF NOT EXISTS pgcrypto; CREATE EXTENSION IF NOT EXISTS citext;'
done
