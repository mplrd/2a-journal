#!/bin/sh
# Scheduler container entrypoint. Diagnostic mode: prints the runtime
# environment, verifies each component independently, then hands off to
# supercronic. If supercronic keeps failing with a cryptic "Failed to fork
# exec" at PID 1, at least we will know from these logs whether the
# PHP binary, the CLI script, and /etc/crontab are all in order.

set -e

echo "[scheduler] ========== env =========="
echo "[scheduler] whoami=$(whoami)"
echo "[scheduler] pwd=$(pwd)"
echo "[scheduler] PATH=${PATH}"
echo "[scheduler] which php: $(command -v php || echo 'NOT FOUND')"
echo "[scheduler] which supercronic: $(command -v supercronic || echo 'NOT FOUND')"

echo "[scheduler] ========== files =========="
ls -la /usr/local/bin/php /usr/local/bin/supercronic /etc/crontab /var/www/api/cli/sync-brokers.php 2>&1 || true

echo "[scheduler] ========== crontab content =========="
cat /etc/crontab

echo "[scheduler] ========== php smoke test =========="
/usr/local/bin/php --version

echo "[scheduler] ========== php CLI dry run =========="
# Actually execute the CLI once at startup. If this works, the exec-chain
# is fine end-to-end. If supercronic still fails after this, the bug is
# in supercronic itself, not in our payload.
/usr/local/bin/php /var/www/api/cli/sync-brokers.php || echo "[scheduler] dry run exit=$?"

echo "[scheduler] ========== handing off to supercronic =========="
exec supercronic /etc/crontab
