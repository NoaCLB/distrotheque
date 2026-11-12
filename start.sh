#!/usr/bin/env sh

set -eu

cd "$(dirname "$0")"

PYTHON="${DISTROTHEQUE_PYTHON:-}"
if [ -z "$PYTHON" ]; then
    for candidate in python3 python; do
        if command -v "$candidate" >/dev/null 2>&1; then
            PYTHON="$candidate"
            break
        fi
    done
fi

if [ -z "$PYTHON" ]; then
    echo "Python 3.9+ est introuvable dans le PATH." >&2
    echo "Installe-le, ou indique le binaire avec DISTROTHEQUE_PYTHON." >&2
    exit 1
fi

exec "$PYTHON" start.py "$@"
