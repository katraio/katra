#!/bin/sh
set -eu

psql --set ON_ERROR_STOP=1 --username "$POSTGRES_USER" --dbname "$POSTGRES_DB" <<-'SQL'
SELECT 'CREATE DATABASE katra_testing OWNER katra'
WHERE NOT EXISTS (SELECT FROM pg_database WHERE datname = 'katra_testing')\gexec
SQL
