#!/bin/sh
# exec "$@" lets one image run every process and makes the command PID 1, so SIGTERM reaches it directly.
set -eu

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
