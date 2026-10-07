#!/usr/bin/env bash
# Runs unit tests in a throwaway PHP container (not the docker-compose services).
set -euo pipefail
cd "$(dirname "$0")/../.."
docker run --rm -v "$PWD:/app" -w /app php:8.3-cli sh -c 'for t in tests/unit/*Test.php; do echo "== $t"; php "$t" || exit 1; done'
