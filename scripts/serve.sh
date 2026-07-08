#!/bin/bash
# Start the local dev server via WSL
# Usage: bash scripts/serve.sh [port]
# Default port: 8000

PORT="${1:-8000}"
PROJECT="/mnt/c/Users/CharlieCoutts/GitHub/soundit-psa"

echo "Starting Laravel dev server on http://localhost:$PORT"
echo "Press Ctrl+C to stop"
echo ""

# Detect if already in WSL
if grep -qEi "(Microsoft|WSL)" /proc/version 2>/dev/null; then
    cd "$(dirname "$0")/.." && php artisan serve --host=0.0.0.0 --port="$PORT"
else
    wsl.exe -- bash -c "cd $PROJECT && php artisan serve --host=0.0.0.0 --port=$PORT"
fi
