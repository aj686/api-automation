#!/bin/sh
# Runs INSIDE mcr.microsoft.com/playwright:v$PW_VERSION-noble (see scripts/browser-e2e.sh).
# The image ships the browsers; only the matching npm package is installed, into /tmp.
set -e
mkdir -p /tmp/pw && cd /tmp/pw
npm init -y >/dev/null 2>&1
npm install --no-audit --no-fund --silent "playwright@${PW_VERSION:-1.63.0}" >/dev/null 2>&1
cp /browser/e2e.mjs /tmp/pw/e2e.mjs
node /tmp/pw/e2e.mjs
