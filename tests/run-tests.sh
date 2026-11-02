#!/bin/sh
# Builds the application, starts an isolated test stack (in-memory database),
# runs the migration and API tests, then removes the test stack.
#
#   sh tests/run-tests.sh            (from the repository root)
set -e
cd "$(dirname "$0")"
COMPOSE="docker compose -f docker-compose.yml"

cleanup() { $COMPOSE down -v --remove-orphans >/dev/null 2>&1 || true; }
trap cleanup EXIT

echo "== Building and starting the test stack"
$COMPOSE up -d --build --wait --wait-timeout 300

echo "== Migration test"
$COMPOSE exec -T web php /tests/migration-test.php

echo "== API tests"
$COMPOSE exec -T web php /tests/api-test.php http://127.0.0.1

echo "== Session survives a restart of the web container"
$COMPOSE exec -T web php /tests/api-test.php http://127.0.0.1 --save-session /tmp/session.txt >/dev/null
TOKEN=$($COMPOSE exec -T web cat /tmp/session.txt)
$COMPOSE restart web >/dev/null
$COMPOSE up -d --wait --wait-timeout 120 >/dev/null
$COMPOSE exec -T web php /tests/api-test.php http://127.0.0.1 --check-session "$TOKEN"

echo "All tests passed."
