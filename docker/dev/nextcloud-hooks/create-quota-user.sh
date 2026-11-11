#!/bin/sh
# Runs once after the test Nextcloud is installed (post-installation hook of the official image).
# Creates a test-only user with a 1 MB quota, so tests can trigger "507 Insufficient Storage".
set -eu

OC_PASS="${NEXTCLOUD_QUOTA_USER_PASSWORD:?}" php /var/www/html/occ user:add --password-from-env --display-name "Quota test" quota-user
php /var/www/html/occ user:setting quota-user files quota "1 MB"
