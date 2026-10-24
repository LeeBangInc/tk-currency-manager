#!/usr/bin/env sh
set -eu
cd "$(dirname "$0")/.."
for file in tk-currency-manager.php uninstall.php includes/*.php tests/*.php; do
    php -l "$file"
done
php tests/validation.php
php tests/bootstrap.php before
php tests/bootstrap.php after
php tests/bootstrap.php absent
