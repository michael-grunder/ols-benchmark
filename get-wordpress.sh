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

# Remove prior WP_HOME/SITEURL if present
"${SEDI[@]}" -E "/define\([[:space:]]*'WP_HOME'[[:space:]]*,/d" wp-config.php
"${SEDI[@]}" -E "/define\([[:space:]]*'WP_SITEURL'[[:space:]]*,/d" wp-config.php

# Insert WP_HOME/SITEURL right above first DB_NAME define
awk '
  BEGIN { ins=0 }
  ins==0 && /define\(..DB_NAME../ {
    print "define('\''WP_HOME'\'','\''http://localhost:8080'\'');";
    print "define('\''WP_SITEURL'\'','\''http://localhost:8080'\'');";
    ins=1
  }
  { print }
' wp-config.php > wp-config.php.new && mv wp-config.php.new wp-config.php

# Optional: avoid FS perms prompts in containers
grep -q "define('FS_METHOD'" wp-config.php || \
  printf "%s\n" "define('FS_METHOD','direct');" >> wp-config.php

echo "wp-config.php updated."
