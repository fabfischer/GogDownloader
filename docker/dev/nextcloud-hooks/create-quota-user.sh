#!/bin/sh
# Runs once after a test Nextcloud is installed (post-installation hook of the official image).
# - Creates a test-only user with a 1 MB quota, so tests can trigger "507 Insufficient Storage".
# - Turns off brute force protection in these throwaway containers: tests send wrong passwords on purpose, and
#   Nextcloud would then slow down every later request from the test container's address.
set -eu

OC_PASS="${NEXTCLOUD_QUOTA_USER_PASSWORD:?}" php /var/www/html/occ user:add --password-from-env --display-name "Quota test" quota-user
php /var/www/html/occ user:setting quota-user files quota "1 MB"
php /var/www/html/occ config:system:set auth.bruteforce.protection.enabled --type=boolean --value=false
