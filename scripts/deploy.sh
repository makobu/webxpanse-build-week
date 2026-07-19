#!/bin/bash
# Backward-compatible wrapper for the current production deployment helper.

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
exec "$SCRIPT_DIR/deploy_to_production.sh" "$@"
