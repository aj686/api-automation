#!/bin/sh
# Clicks the master prompt section 99 path in real Chromium (decisions.md D-034):
# create project → environment + import → collection import → Run → PASS 6/6 →
# run page and filters → capture URL → settings → delete project.
# Livewire server-side tests cannot catch browser-only bugs (D-026); this can.
#
# Run from WSL, in the app root, with the stack and worker up:
#   sh scripts/browser-e2e.sh
#
# Uses a throwaway Playwright container (image ~3.5 GB, pulled on first use;
# remove with: docker rmi mcr.microsoft.com/playwright:v1.63.0-noble).
# Another version: PW_VERSION=1.62.1 sh scripts/browser-e2e.sh
# Works in a throwaway project "Zz Browser E2E" and removes it again.
# Screenshots: storage/framework/testing/browser/ (gitignored).

set -u
APP="$(cd "$(dirname "$0")/.." && pwd)"
IN_CONTAINER=/var/www/projects/api-automation/api-automation
# The image tag and the npm package must be the same version.
PW_VERSION="${PW_VERSION:-1.63.0}"
IMAGE="mcr.microsoft.com/playwright:v${PW_VERSION}-noble"
OUT="$APP/storage/framework/testing/browser"

cleanup() {
    docker exec -w "$IN_CONTAINER" php8.3.fpm php artisan tinker --execute \
        'App\Models\Run::where("project_name","Zz Browser E2E")->delete(); App\Models\Project::where("slug","zz-browser-e2e")->first()?->delete();' >/dev/null 2>&1
}

# A running `npm run dev` writes public/hot, and pages then load CSS/JS from
# 127.0.0.1:<port> — which inside the test container is the container itself,
# so the browser logs connection errors and pages render unstyled.
if [ -f "$APP/public/hot" ] && [ "${E2E_ALLOW_HOT:-0}" != "1" ]; then
    echo "A Vite dev server is active ($(cat "$APP/public/hot")): stop 'npm run dev' first, so the" >&2
    echo "built assets are used — or run with E2E_ALLOW_HOT=1 to test without them (expect" >&2
    echo "ERR_CONNECTION_REFUSED console errors for the dev-server files)." >&2
    exit 2
fi

mkdir -p "$OUT" && rm -f "$OUT"/*.png
cleanup

docker run --rm --init --ipc=host \
    --network docker-webstack_docker_webstack \
    --add-host api-automation.local:host-gateway \
    -v "$APP/tests/browser:/browser:ro" -v "$APP/demo:/demo:ro" -v "$OUT:/out" \
    -e PW_VERSION="$PW_VERSION" \
    -e E2E_HOT_ORIGIN="$( [ "${E2E_ALLOW_HOT:-0}" = "1" ] && cat "$APP/public/hot" 2>/dev/null )" \
    --user root "$IMAGE" sh /browser/run-in-container.sh
status=$?

cleanup
echo "Screenshots: $OUT"
exit $status
