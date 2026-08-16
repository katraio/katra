#!/bin/sh
set -eu

npm install --no-audit --no-fund

exec npm run dev -- --host=0.0.0.0 --port=5173
