#!/usr/bin/env bash

set -eao pipefail

cd www || { 
    2>&1 echo "Directory www does not exist."; 
    exit 1; 
}

curl -O https://wordpress.org/latest.tar.gz || {
    2>&1 echo "Failed to download WordPress."; 
    exit 1;
}

tar xzf latest.tar.gz --strip-components=1 || {
    2>&1 echo "Failed to extract WordPress."; 
    exit 1;
}

mv wp-config-sample.php wp-config.php || {
    2>&1 echo "Failed to rename wp-config-sample.php to wp-config.php."; 
    exit 1;
}

VARS=('WP_HOME' 'WP_SITEURL' 'DB_NAME' 'DB_USER' 'DB_PASSWORD' 'DB_HOST')

if [[ "$(uname)" == "Darwin" ]]; then
    SED_COMMAND="sed -i ''"
else
    SED_COMMAND="sed -i"
fi

for VAR in "${VARS[@]}"; do

    $SED_COMMAND "/define( '$VAR'/d" wp-config.php || {
        2>&1 echo "Failed to remove existing definition of $VAR in wp-config.php."; 
        exit 1;
    }
done

cat >> wp-config.php <<'PHP'
  define('WP_HOME', 'http://localhost:8080');
  define('WP_SITEURL', 'http://localhost:8080');
  define('DB_NAME', 'wordpress');
  define('DB_USER', 'wordpress');
  define('DB_PASSWORD', 'wordpress');
  define('DB_HOST', 'db');
PHP
