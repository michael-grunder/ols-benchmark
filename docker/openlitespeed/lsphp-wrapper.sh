#!/usr/bin/env bash
set -euo pipefail

: "${PHP_MINOR:=83}"
REAL="/usr/local/lsws/lsphp${PHP_MINOR}/bin/lsphp"

# source the generated env file (from entrypoint)
ENV_FILE="/etc/lsapi.env"
if [[ -f "$ENV_FILE" ]]; then
  # shellcheck disable=SC2046
  export $(grep -v '^\s*#' "$ENV_FILE" | xargs -r)
fi

# defaults if not set
: "${LSAPI_CHILDREN:=20}"
: "${LSAPI_MAX_REQS:=2000}"
: "${LSAPI_PGRP_MAX_IDLE:=60}"
: "${LSAPI_MAX_PROCESS_TIME:=180}"
: "${LSAPI_AVOID_FORK:=1}"
: "${LSAPI_SLOW_REQ_TIME:=10}"
: "${LSAPI_RESTART_ON_CRASH:=1}"

export LSAPI_CHILDREN \
       LSAPI_MAX_REQS \
       LSAPI_PGRP_MAX_IDLE \
       LSAPI_MAX_PROCESS_TIME \
       LSAPI_AVOID_FORK \
       LSAPI_SLOW_REQ_TIME \
       LSAPI_RESTART_ON_CRASH

exec "$REAL" "$@"
