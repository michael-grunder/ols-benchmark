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
