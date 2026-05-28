#!/bin/bash
# Run php artisan commands via WSL (avoids quoting hell)
# Usage: bash scripts/artisan.sh migrate
#        bash scripts/artisan.sh serve --port=8000
#        bash scripts/artisan.sh tinker

ARGS="$*"

if [ -z "$ARGS" ]; then
    echo "Usage: bash scripts/artisan.sh <command>"
    echo "Examples:"
    echo "  bash scripts/artisan.sh serve"
    echo "  bash scripts/artisan.sh migrate"
    echo "  bash scripts/artisan.sh route:list"
    echo "  bash scripts/artisan.sh tinker"
    exit 1
fi

# Detect if we're already in WSL or need to call wsl.exe
if grep -qEi "(Microsoft|WSL)" /proc/version 2>/dev/null; then
    # Already in WSL
    cd "$(dirname "$0")/.." && php artisan $ARGS
else
    # Running from Windows (Git Bash / Claude terminal)
    WINPATH="$(cd "$(dirname "$0")/.." && pwd)"
    WSLPATH=$(wsl.exe -- wslpath -u "$WINPATH" 2>/dev/null || echo "/path/to/your/psa")
    wsl.exe -- bash -c "cd $WSLPATH && php artisan $ARGS"
fi
