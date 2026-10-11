#!/bin/sh
# Re-checks the Postman CLI facts the runner and the report parser rely on
# (decisions.md D-022). Runs INSIDE the worker container:
#
#   docker exec -w /var/www/projects/api-automation/api-automation \
#       api-automation-worker sh scripts/verify-postman-cli.sh [output-dir]
#
# Synthetic collections only; the one "secret" is the placeholder YOUR_API_KEY.
# The target is this app's own /up health route through the nginx container.
# Writes <case>.report.json, <case>.stdout.txt and exit-codes.txt to output-dir.

set -u
OUT="${1:-/tmp/postman-verify}"
BASE_URL="${BASE_URL:-http://nginx}"
HOST_HEADER="${HOST_HEADER:-api-automation.local}"
CLI="${POSTMAN_CLI_PATH:-postman}"

WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT
mkdir -p "$OUT"
: > "$OUT/exit-codes.txt"

echo "postman --version: $("$CLI" --version 2>&1 | tail -n 1)" | tee "$OUT/version.txt"

cat > "$WORK/env.json" <<JSON
{"name":"verify","values":[
 {"key":"base_url","value":"$BASE_URL","type":"default","enabled":true},
 {"key":"host","value":"$HOST_HEADER","type":"default","enabled":true},
 {"key":"api_key","value":"YOUR_API_KEY","type":"secret","enabled":true}]}
JSON

# collection <case> <items-json>
collection() {
    printf '{"info":{"name":"Verify %s","schema":"https://schema.getpostman.com/json/collection/v2.1.0/collection.json"},"item":[%s]}' "$1" "$2" > "$WORK/$1.json"
}
health() { # health <name> <test-script-lines-json>
    printf '{"name":"%s","request":{"method":"GET","header":[{"key":"Host","value":"{{host}}"}],"url":"{{base_url}}/up?api_key={{api_key}}"},"event":[{"listen":"test","script":{"exec":%s}}]}' "$1" "$2"
}

collection pass "$(health Health '["pm.test(\"status is 200\", function () { pm.response.to.have.status(200); });","pm.test(\"is html\", function () { pm.expect(pm.response.text()).to.include(\"html\"); });"]')"
collection fail "$(health Health '["pm.test(\"expects 201\", function () { pm.response.to.have.status(201); });","pm.test(\"is html\", function () { pm.expect(pm.response.text()).to.include(\"html\"); });"]'),$(printf '{"name":"Missing","request":{"method":"GET","header":[{"key":"Host","value":"{{host}}"}],"url":"{{base_url}}/api/v1/nope"},"event":[{"listen":"test","script":{"exec":["pm.test(\\"404 expected\\", function () { pm.response.to.have.status(404); });"]}}]}')"
collection unreachable '{"name":"Nowhere","request":{"method":"GET","url":"http://does-not-exist.invalid/x"},"event":[{"listen":"test","script":{"exec":["pm.test(\"status is 200\", function () { pm.response.to.have.status(200); });"]}}]}'
collection scripterror "$(health Health '["undefinedFunction();"]')"
collection notests '{"name":"Health","request":{"method":"GET","header":[{"key":"Host","value":"{{host}}"}],"url":"{{base_url}}/up"}}'
collection requesttimeout '{"name":"Blackhole","request":{"method":"GET","url":"http://10.255.255.1/x"},"event":[{"listen":"test","script":{"exec":["pm.test(\"status is 200\", function () { pm.response.to.have.status(200); });"]}}]}'
echo '{not json' > "$WORK/brokenjson.json"

# Payload case (D-037): request and response bodies, headers, a binary body.
# A throwaway server inside this container answers on 127.0.0.1:8099.
cat > "$WORK/server.mjs" <<'JS'
import http from 'node:http';
const png = Buffer.from('89504e470d0a1a0a0000000d4948445200000001000000010806000000', 'hex');
http.createServer((req, res) => {
  let body = '';
  req.on('data', (c) => (body += c));
  req.on('end', () => {
    if (req.url.startsWith('/echo')) {
      let received; try { received = JSON.parse(body || '{}'); } catch { received = body; }
      res.writeHead(201, { 'Content-Type': 'application/json', 'X-Request-Id': 'abc-123', 'Set-Cookie': 'session=YOUR_SESSION_COOKIE; HttpOnly' });
      res.end(JSON.stringify({ received, access_token: 'YOUR_ACCESS_TOKEN_issued' }));
    } else if (req.url === '/png') {
      res.writeHead(200, { 'Content-Type': 'image/png' }); res.end(png);
    } else {
      res.writeHead(200, { 'Content-Type': 'application/json' }); res.end('{"ok":true}');
    }
  });
}).listen(8099, '127.0.0.1');
JS
node "$WORK/server.mjs" &
PAYLOAD_SERVER=$!
sleep 1
cat > "$WORK/payloadenv.json" <<JSON
{"name":"payload","values":[
 {"key":"base_url","value":"http://127.0.0.1:8099","type":"default","enabled":true},
 {"key":"api_key","value":"YOUR_API_KEY","type":"secret","enabled":true}]}
JSON
collection payload '{"name":"Create order","request":{"method":"POST","header":[{"key":"Content-Type","value":"application/json"},{"key":"X-Api-Key","value":"{{api_key}}"},{"key":"Authorization","value":"Bearer {{api_key}}"}],"body":{"mode":"raw","raw":"{\"order\": 7, \"note\": \"key {{api_key}}\"}"},"url":"{{base_url}}/echo?api_key={{api_key}}"},"event":[{"listen":"test","script":{"exec":["pm.test(\"created\", function () { pm.response.to.have.status(201); });"]}}]},{"name":"Form login","request":{"method":"POST","body":{"mode":"urlencoded","urlencoded":[{"key":"username","value":"me"},{"key":"password","value":"{{api_key}}"}]},"url":"{{base_url}}/echo"}},{"name":"Logo","request":{"method":"GET","url":"{{base_url}}/png"}}'

# The exact flags App\Services\Postman\RunCommandBuilder uses.
run_case() {
    case_name="$1"; shift
    rm -f "$OUT/$case_name.report.json"
    setsid "$CLI" collection run "$WORK/$case_name.json" -e "${ENV_FILE:-$WORK/env.json}" \
        --working-dir "$WORK" --no-insecure-file-read --no-report-events \
        -r cli,json --reporter-json-export "$OUT/$case_name.report.json" \
        --disable-unicode "$@" \
        > "$OUT/$case_name.stdout.txt" 2>&1
    code=$?
    report=no; [ -f "$OUT/$case_name.report.json" ] && report=yes
    echo "$case_name exit=$code report=$report" | tee -a "$OUT/exit-codes.txt"
}

run_case pass --timeout-request 30000
run_case fail --timeout-request 30000
run_case unreachable --timeout-request 30000
run_case scripterror --timeout-request 30000
run_case notests --timeout-request 30000
run_case requesttimeout --timeout-request 2000
run_case brokenjson --timeout-request 30000
ENV_FILE="$WORK/payloadenv.json" run_case payload --timeout-request 30000
kill "$PAYLOAD_SERVER" 2>/dev/null

# Resolved secrets in raw CLI output (the app redacts before storing anything).
leaks=$(grep -l YOUR_API_KEY "$OUT"/*.report.json "$OUT"/*.stdout.txt 2>/dev/null | xargs -r -n1 basename | tr '\n' ' ')
echo "placeholder secret present in raw output of: ${leaks:-nothing}" | tee -a "$OUT/exit-codes.txt"
