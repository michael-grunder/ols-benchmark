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
: "${PHP_LSAPI_CHILDREN:=20}"
: "${LSAPI_MAX_REQUESTS:=2000}"
: "${LSAPI_MAX_PROCESS_TIME:=180}"
: "${LSAPI_PGRP_MAX_IDLE:=60}"
: "${LSAPI_AVOID_FORK:=1}"

export PHP_LSAPI_CHILDREN \
       LSAPI_MAX_REQUESTS \
       LSAPI_MAX_PROCESS_TIME \
       LSAPI_PGRP_MAX_IDLE \
       LSAPI_AVOID_FORK

exec "$REAL" "$@"
