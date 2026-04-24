#!/bin/sh
# Scheduler container entrypoint.
#
# Debian cron does not inherit the container env — we have to capture it
# explicitly. We write the important vars to /etc/container-env, which the
# cron jobs `source` via the crontab before running. Then we start cron in
# the foreground and tail the job log so output lands in Railway logs.

set -e

echo "[scheduler] ========== env =========="
echo "[scheduler] whoami=$(whoami)"
echo "[scheduler] pwd=$(pwd)"
echo "[scheduler] PATH=${PATH}"
echo "[scheduler] BROKER_ENCRYPTION_KEY set: $([ -n "${BROKER_ENCRYPTION_KEY}" ] && echo yes || echo NO)"
echo "[scheduler] BROKER_AUTO_SYNC_ENABLED=${BROKER_AUTO_SYNC_ENABLED:-<unset>}"
echo "[scheduler] DB_HOST=${DB_HOST:-<unset>}"

if [ -z "${BROKER_ENCRYPTION_KEY}" ]; then
    echo "[scheduler] FATAL: BROKER_ENCRYPTION_KEY is not set. The PHP bootstrap will refuse to start."
    echo "[scheduler] Add BROKER_ENCRYPTION_KEY on the scheduler service (same value as on the api service)."
    exit 1
fi

# Capture the container env for cron to consume. Only pass the vars the
# scheduler actually needs, never things like JWT_SECRET or STRIPE_*.
cat > /etc/container-env <<EOF
APP_ENV='${APP_ENV:-production}'
APP_DEBUG='${APP_DEBUG:-false}'
DB_HOST='${DB_HOST}'
DB_PORT='${DB_PORT}'
DB_NAME='${DB_NAME}'
DB_USER='${DB_USER}'
DB_PASSWORD='${DB_PASSWORD}'
BROKER_ENCRYPTION_KEY='${BROKER_ENCRYPTION_KEY}'
BROKER_AUTO_SYNC_ENABLED='${BROKER_AUTO_SYNC_ENABLED:-false}'
BROKER_SYNC_INTERVAL_MINUTES='${BROKER_SYNC_INTERVAL_MINUTES:-15}'
BROKER_SYNC_MAX_FAILURES='${BROKER_SYNC_MAX_FAILURES:-3}'
CTRADER_WS_HOST='${CTRADER_WS_HOST:-}'
CTRADER_WS_PORT='${CTRADER_WS_PORT:-}'
METAAPI_BASE_URL='${METAAPI_BASE_URL:-}'

export APP_ENV APP_DEBUG DB_HOST DB_PORT DB_NAME DB_USER DB_PASSWORD
export BROKER_ENCRYPTION_KEY BROKER_AUTO_SYNC_ENABLED BROKER_SYNC_INTERVAL_MINUTES BROKER_SYNC_MAX_FAILURES
export CTRADER_WS_HOST CTRADER_WS_PORT METAAPI_BASE_URL
EOF
chmod 0600 /etc/container-env

echo "[scheduler] ========== PHP CLI smoke test =========="
# Run the CLI once at startup so any bootstrap error surfaces here rather
# than hiding inside a cron log. `. /etc/container-env` mirrors what cron
# jobs will do so we test the exact path they will use.
# Explicit disable of `set -e` around the test so we always print the exit
# code, even on failure.
set +e
(. /etc/container-env && /usr/local/bin/php /var/www/api/cli/sync-brokers.php)
smoke_exit=$?
set -e
echo "[scheduler] smoke test exit=${smoke_exit}"
if [ "${smoke_exit}" -ne 0 ]; then
    echo "[scheduler] FATAL: smoke test failed; refusing to start cron with a broken payload."
    exit 1
fi

echo "[scheduler] ========== starting cron =========="
# cron -f: foreground (required for Docker); -L 15: full log level (job + cron msgs)
cron -f -L 15 &
cron_pid=$!

# Tail the job log into this process stdout so Railway logs show job output.
touch /var/log/cron.log
tail -F /var/log/cron.log &

# Wait on cron; if it dies, the container exits and Railway restarts it.
wait ${cron_pid}
