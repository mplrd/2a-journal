#!/bin/bash
# Run PHPUnit tests then re-seed demo data (tests clean all tables)
cd "$(dirname "$0")"
php vendor/bin/phpunit "$@"
TEST_EXIT=$?
echo ""
echo "── Re-seeding demo data ──"
php database/seed-demo.php
exit $TEST_EXIT
