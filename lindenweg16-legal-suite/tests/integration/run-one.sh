#!/usr/bin/env bash
# Aufruf: WP_PATH=/pfad/zu/wordpress tests/integration/run-one.sh 01-activation.php
set -u
HERE="$(cd "$(dirname "$0")" && pwd)"
WPCLI="${WPCLI:-wp}"
exec $WPCLI --allow-root --path="$WP_PATH" eval-file "$HERE/$1"
