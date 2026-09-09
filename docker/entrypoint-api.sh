#!/bin/sh
set -eu

case "${SSH_RELAY_ENABLED:-false}" in
    1|true|TRUE|yes|YES)
        if [ -z "${SSH_RELAY_INTERNAL_TOKEN:-}" ]; then
            echo "SSH_RELAY_ENABLED requires SSH_RELAY_INTERNAL_TOKEN" >&2
            exit 1
        fi
        /usr/local/bin/galaxy-ssh-relay &
        ;;
esac

case "${HELM_SERVICE_ENABLED:-true}" in
    1|true|TRUE|yes|YES)
        if [ -z "${HELM_SERVICE_INTERNAL_TOKEN:-}" ]; then
            HELM_SERVICE_INTERNAL_TOKEN="$(od -An -N32 -tx1 /dev/urandom | tr -d ' \n')"
            export HELM_SERVICE_INTERNAL_TOKEN
        fi
        /usr/local/bin/galaxy-helm-service &
        ;;
esac

exec php bin/hyperf.php start
