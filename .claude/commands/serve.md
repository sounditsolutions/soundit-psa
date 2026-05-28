# Start local dev server

Start the PHP development server for local testing.

## Steps

1. Kill any existing server on port 8000:
   ```bash
   fuser -k 8000/tcp 2>/dev/null; echo 'port cleared'
   ```

2. Start the PHP server on the internal port and verify it responds:
   ```bash
   cd ~/repos/soundit-psa && php -S 127.0.0.1:8080 -t public &>/tmp/psa-server.log & SRVPID=$!; sleep 2; RESULT=$(curl -sS -w "\nHTTP:%{http_code}" http://127.0.0.1:8080/login 2>&1 | tail -3); echo "PID: $SRVPID"; echo "$RESULT"
   ```

3. Report the result to the user. HTTP:200 or HTTP:302 means the server is running. Access via browser at https://172.25.229.117 (nginx handles SSL on port 443 and proxies to PHP on 8080).

## Important notes
- PHP listens on `127.0.0.1:8080` (internal only) — nginx handles SSL on port 8000 and proxies to it
- Use `php -S 127.0.0.1:8080 -t public` for automated/background testing — NOT `php artisan serve`
- `php artisan serve` is fine when the user wants to run a persistent server themselves in a terminal
- Use `fuser -k 8080/tcp` to kill the PHP server by port — do NOT use `pkill php`
- nginx must be running (`sudo systemctl status nginx`) for browser access to work
- Server log is at `/tmp/psa-server.log`
