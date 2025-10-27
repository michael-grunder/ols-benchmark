#!/usr/bin/env bash
set -euo pipefail

cd www || { echo "Directory www does not exist." >&2; exit 1; }

# Fetch WP if needed
[ -f wp-config-sample.php ] || {
  curl -fsSLO https://wordpress.org/latest.tar.gz
  tar xzf latest.tar.gz --strip-components=1
}

# Prepare wp-config.php
[ -f wp-config.php ] || cp wp-config-sample.php wp-config.php

# BSD vs GNU sed in-place
if [[ "$(uname)" == "Darwin" ]]; then
  SEDI=(sed -i '')
else
  SEDI=(sed -i)
fi

# Replace the DB_* lines (portable patterns)
"${SEDI[@]}" -E \
  "s@define\([[:space:]]*'DB_NAME'[[:space:]]*,[[:space:]]*'[^']*'\
[[:space:]]*\);@define('DB_NAME','wordpress');@" wp-config.php

"${SEDI[@]}" -E \
  "s@define\([[:space:]]*'DB_USER'[[:space:]]*,[[:space:]]*'[^']*'\
[[:space:]]*\);@define('DB_USER','wordpress');@" wp-config.php

"${SEDI[@]}" -E \
  "s@define\([[:space:]]*'DB_PASSWORD'[[:space:]]*,[[:space:]]*'[^']*'\
[[:space:]]*\);@define('DB_PASSWORD','wordpress');@" wp-config.php

"${SEDI[@]}" -E \
  "s@define\([[:space:]]*'DB_HOST'[[:space:]]*,[[:space:]]*'[^']*'\
[[:space:]]*\);@define('DB_HOST','db');@" wp-config.php

# Optional: avoid FS perms prompts in containers
grep -q "define('FS_METHOD'" wp-config.php || \
  printf "%s\n" "define('FS_METHOD','direct');" >> wp-config.php

echo "wp-config.php updated."
