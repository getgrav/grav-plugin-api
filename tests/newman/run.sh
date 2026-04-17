#!/bin/bash
#
# Newman API Integration Test Runner
#
# Usage:
#   ./tests/newman/run.sh [--base-url URL] [--user USER] [--password PASS] [--env ENV]
#
# Defaults to localhost with admin/Password1

set -e

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"

# Defaults (reads from env vars set in ~/.zshrc if available)
BASE_URL="${GRAV_BASE_URL:-${BASE_URL:-https://localhost/grav-api}}"
API_PREFIX="${GRAV_API_PREFIX:-/api/v1}"
GRAV_ENV="${GRAV_ENVIRONMENT:-${GRAV_ENV:-localhost}}"
USERNAME="${USERNAME:-admin}"
PASSWORD="${PASSWORD:-Password1}"
STATIC_API_KEY="${GRAV_API_KEY:-}"
CURL_OPTS="-sk"

# Parse args
while [[ $# -gt 0 ]]; do
  case $1 in
    --base-url) BASE_URL="$2"; shift 2;;
    --user) USERNAME="$2"; shift 2;;
    --password) PASSWORD="$2"; shift 2;;
    --env) GRAV_ENV="$2"; shift 2;;
    *) echo "Unknown option: $1"; exit 1;;
  esac
done

API_BASE="${BASE_URL}${API_PREFIX}"

echo "============================================"
echo "  Grav API — Newman Integration Tests"
echo "============================================"
echo "  Server:      ${BASE_URL}"
echo "  Environment: ${GRAV_ENV}"
echo "  User:        ${USERNAME}"
echo ""

# Step 1: Authenticate and get JWT token
echo "→ Authenticating..."
AUTH_RESPONSE=$(curl ${CURL_OPTS} "${API_BASE}/auth/token" \
  -H "Content-Type: application/json" \
  -H "X-Grav-Environment: ${GRAV_ENV}" \
  -d "{\"username\":\"${USERNAME}\",\"password\":\"${PASSWORD}\"}")

ACCESS_TOKEN=$(echo "$AUTH_RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['access_token'])" 2>/dev/null)

if [ -z "$ACCESS_TOKEN" ]; then
  echo "  ✗ Authentication failed"
  echo "  Response: $AUTH_RESPONSE"
  exit 1
fi
echo "  ✓ Got JWT token"

# Step 2: Use static API key from env, or create a temporary one
CLEANUP_KEY=false
if [ -n "$STATIC_API_KEY" ]; then
  API_KEY="$STATIC_API_KEY"
  echo "→ Using API key from environment: ${API_KEY:0:20}..."
else
  echo "→ Creating test API key..."
  KEY_RESPONSE=$(curl ${CURL_OPTS} "${API_BASE}/users/${USERNAME}/api-keys" \
    -H "X-API-Token: ${ACCESS_TOKEN}" \
    -H "X-Grav-Environment: ${GRAV_ENV}" \
    -H "Content-Type: application/json" \
    -d '{"name":"Newman Test Key","expires_in_days":1}')

  API_KEY=$(echo "$KEY_RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['api_key'])" 2>/dev/null)
  KEY_ID=$(echo "$KEY_RESPONSE" | python3 -c "import sys,json; print(json.load(sys.stdin)['data']['id'])" 2>/dev/null)

  if [ -z "$API_KEY" ]; then
    echo "  ✗ Failed to create API key"
    echo "  Response: $KEY_RESPONSE"
    exit 1
  fi
  echo "  ✓ Created API key: ${API_KEY:0:20}..."
  CLEANUP_KEY=true
fi

# Step 3: Generate temporary environment file with real credentials
TEMP_ENV=$(mktemp)
cat > "$TEMP_ENV" << EOF
{
  "id": "grav-api-newman-test",
  "name": "Newman Test (auto-generated)",
  "values": [
    {"key": "base_url", "value": "${BASE_URL}", "enabled": true},
    {"key": "api_prefix", "value": "${API_PREFIX}", "enabled": true},
    {"key": "grav_environment", "value": "${GRAV_ENV}", "enabled": true},
    {"key": "username", "value": "${USERNAME}", "enabled": true},
    {"key": "password", "value": "${PASSWORD}", "enabled": true},
    {"key": "api_key", "value": "${API_KEY}", "enabled": true},
    {"key": "access_token", "value": "", "enabled": true},
    {"key": "refresh_token", "value": "", "enabled": true},
    {"key": "test_username", "value": "testuser", "enabled": true},
    {"key": "test_api_key_id", "value": "", "enabled": true},
    {"key": "page_route", "value": "blog", "enabled": true},
    {"key": "lang", "value": "en", "enabled": true},
    {"key": "package_slug", "value": "", "enabled": true},
    {"key": "webhook_id", "value": "", "enabled": true},
    {"key": "notification_id", "value": "", "enabled": true}
  ]
}
EOF

# Step 4: Run Newman
echo ""
echo "→ Running Newman tests..."
echo ""

NEWMAN_BIN="${PROJECT_DIR}/node_modules/.bin/newman"

${NEWMAN_BIN} run "${PROJECT_DIR}/grav-api.postman_collection.json" \
  --environment "$TEMP_ENV" \
  --env-var "api_key=${API_KEY}" \
  --env-var "base_url=${BASE_URL}" \
  --env-var "api_prefix=${API_PREFIX}" \
  --env-var "grav_environment=${GRAV_ENV}" \
  --env-var "username=${USERNAME}" \
  --env-var "password=${PASSWORD}" \
  --env-var "test_username=${GRAV_TEST_USERNAME:-test_username}" \
  --env-var "page_route=${GRAV_PAGE_ROUTE:-typography}" \
  --env-var "lang=${GRAV_LANG:-en}" \
  --env-var "package_slug=${GRAV_PACKAGE_SLUG:-form}" \
  --insecure \
  --reporters cli \
  --color on \
  --timeout-request 10000 \
  "$@"

NEWMAN_EXIT=$?

# Step 5: Cleanup — revoke the test API key (only if we created one)
if [ "$CLEANUP_KEY" = true ] && [ -n "$KEY_ID" ]; then
  echo ""
  echo "→ Cleaning up test API key..."
  curl ${CURL_OPTS} -X DELETE "${API_BASE}/users/${USERNAME}/api-keys/${KEY_ID}" \
    -H "X-API-Token: ${ACCESS_TOKEN}" \
    -H "X-Grav-Environment: ${GRAV_ENV}" \
    -o /dev/null -w "  ✓ Revoked test key (HTTP %{http_code})\n"
fi

rm -f "$TEMP_ENV"

echo ""
if [ $NEWMAN_EXIT -eq 0 ]; then
  echo "============================================"
  echo "  ✓ All tests passed!"
  echo "============================================"
else
  echo "============================================"
  echo "  ✗ Some tests failed (exit code: $NEWMAN_EXIT)"
  echo "============================================"
fi

exit $NEWMAN_EXIT
