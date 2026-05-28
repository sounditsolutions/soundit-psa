# Portal Self-Service Access Request — Design

## Goal

Allow existing contacts (people already in the PSA) to request portal access by verifying their email address, without requiring a staff member to manually invite them.

## Flow

1. Portal login page shows a "Request Access" link below "Forgot your password?"
2. User enters their email on a simple form
3. Backend checks: active Person with that email, `portal_enabled = false`, no duplicate portal user with same email
4. If valid, sends a verification email via Graph with a Laravel signed URL (60-minute expiry)
5. User clicks link → `portal_enabled` set to true → password reset token generated → redirected to the set-password form
6. If email doesn't match or is already enabled, show the same generic "If an account exists..." message (anti-enumeration)

## Routes

All under `portal.` prefix, guest middleware group (`portal.enabled`, `throttle:6,1`):

- `GET /request-access` → show email form
- `POST /request-access` → validate & send verification email
- `GET /verify-access/{person}` → signed URL handler, enables portal, redirects to password reset

## Security

- **Signed URLs** prevent token guessing (Laravel `URL::temporarySignedRoute`)
- **Anti-enumeration**: same response regardless of whether email exists
- **Rate limited**: `throttle:6,1` (same as other guest routes)
- **Eligibility**: only active persons with an email and `portal_enabled = false`
- **Duplicate check**: same as invite flow — rejects if another person already has portal enabled with the same email

## Files Changed

| File | Change |
|------|--------|
| `app/Http/Controllers/Portal/PortalAuthController.php` | Add `showRequestAccess()`, `sendAccessLink()`, `verifyAccess()` methods |
| `routes/portal.php` | Add 3 guest routes |
| `resources/views/portal/auth/request-access.blade.php` | New email form view |
| `resources/views/portal/auth/login.blade.php` | Add "Request Access" link |
