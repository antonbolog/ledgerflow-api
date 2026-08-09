#!/bin/sh
#
# Container entrypoint for every process built from the Ledgerflow application
# image.
#
# Setup runs first, then control is handed to the container's command. The
# closing `exec "$@"` is the contract that lets one artifact serve every
# process in docs/operations/runtime-contract.md:
#
#   (default)                        -> php-fpm --nodaemonize
#   command: php artisan horizon     -> Horizon workers
#   command: php artisan schedule:work
#   command: php artisan reverb:start
#   command: php artisan queue:work sqs
#
# `exec` also makes the command PID 1, so SIGTERM reaches it directly and
# graceful shutdown works without a signal-forwarding wrapper.
set -eu

# Laravel needs these to exist and be writable. They are volume mount points in
# some topologies, so create them at start rather than relying on the image.
for dir in \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/testing \
    storage/framework/views \
    storage/logs \
    bootstrap/cache
do
    [ -d "$dir" ] || mkdir -p "$dir"
done

exec "$@"
