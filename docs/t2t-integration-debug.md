# Tier2Tickets Integration Debug Log

## What We're Doing

We built a custom PSA (Sound PSA) and need Tier2Tickets to submit tickets to it instead of Halo PSA. Since T2T doesn't have a generic API/webhook, we implemented a ConnectWise Manage API compatibility layer — a set of endpoints that speak CW Manage's JSON format so T2T can connect to Sound PSA as if it were ConnectWise Manage.

## What We Built

- **Companyinfo endpoint**: `GET /login/companyinfo/{companyId}` — returns CW-format company/version info
- **Service metadata**: `GET /service/boards`, `/boards/{id}/statuses`, `/boards/{id}/types`, `/boards/{id}/teams`, `/priorities`, `/sources`
- **Ticket CRUD**: `GET/POST/PATCH /service/tickets`
- **Contact lookup/create**: `GET/POST /company/contacts`
- **Asset lookup**: `GET /company/configurations`
- **System info**: `GET /system/info`
- **Auth**: Basic auth with `CompanyId+PublicKey:PrivateKey` format, validated server-side

All endpoints live at `https://your-psa-domain/api/tier2tickets/v4_6_release/apis/3.0/...` except companyinfo which is at the root (`/login/companyinfo/...`).

## T2T Configuration

- **Ticket System API endpoint**: `your-psa-domain/api/tier2tickets/v4_6_release`
- **Ticket System API Key**: `SoundPSA+06a529e131a0:f4797db6b269c8807a259dd230aa0a47` (CompanyId+PublicKey:PrivateKey format)
- **Service board**: `Service Desk` (manually set)
- Other integration defaults set to `__default__`

## What T2T Does During Its Test

Based on nginx access logs, T2T's test button makes these calls:

### Step 1: Unauthenticated connectivity check (curl user-agent)
```
GET /api/tier2tickets/v4_6_release/apis/3.0/service/boards HTTP/1.1
User-Agent: curl/8.5.0
→ 200 OK (returns board list JSON)
```
This started working after we made the boards endpoint unauthenticated.

### Step 2: Authenticated companyinfo call (python-requests user-agent)
```
GET /login/companyinfo/SoundPSA HTTP/1.1
Authorization: Basic base64("SoundPSA+06a529e131a0:f4797db6b269c8807a259dd230aa0a47")
User-Agent: python-requests/2.22.0
→ 200 OK
```

Response body:
```json
{
    "CompanyName": "SoundPSA",
    "CompanyID": "SoundPSA",
    "Codebase": "v4_6_release/",
    "VersionCode": "v2021.3",
    "VersionNumber": "v4.6.1000",
    "IsCloud": "True",
    "SiteUrl": "https://your-psa-domain/api/tier2tickets"
}
```

### Step 3: Nothing
T2T receives the 200 companyinfo response but never makes any subsequent API calls. No boards fetch, no ticket creation, no further requests appear in nginx access logs at all. T2T reports "Unknown error" in its UI.

## What We've Tried

1. **Added companyinfo endpoint** at `/login/companyinfo/{companyId}` — T2T calls this at the domain root, not under `/api`
2. **Made boards endpoint unauthenticated** — T2T's curl connectivity check now succeeds (200)
3. **Fixed auth to return JSON 401** with `WWW-Authenticate: Basic` header instead of HTML error pages
4. **Matched CW companyinfo format exactly** — `IsCloud` as string `"True"` (not boolean), standard `Codebase: "v4_6_release/"`, realistic version numbers
5. **Stripped session/cookie middleware** from companyinfo — no more `Set-Cookie` headers in response
6. **Enriched static metadata** — boards, statuses, types, priorities all return full CW-format objects with `inactiveFlag`, `defaultFlag`, `sortOrder`, board references, etc.
7. **Added extra endpoints** — teams, subtypes, sources, system/info
8. **API key format** — generates full `CompanyId+PublicKey:PrivateKey` string for pasting into T2T

## What We Suspect

T2T gets the companyinfo response successfully (HTTP 200, valid JSON, no cookies, correct CW format) but then either:

1. **Fails parsing/validating something in the companyinfo response** that we can't see — maybe a field value, format, or additional validation we don't know about
2. **Tries to make a follow-up request to a URL we're not seeing** — possibly constructed from companyinfo's Codebase that hits a path not routed through Laravel (nginx 404 before PHP)
3. **Has an internal error** in its Python code processing our response that shows as generic "Unknown error"

## What We Need From T2T Support

1. **What exactly does the T2T integration test do?** What is the sequence of API calls and validations?
2. **What does T2T expect in the companyinfo response?** Are there required fields beyond CompanyName, CompanyID, Codebase, VersionCode, VersionNumber, IsCloud?
3. **After getting companyinfo, what URL does T2T construct for the next API call?** Does it use the user-entered endpoint path, or does it reconstruct from host + Codebase?
4. **Can T2T provide debug/verbose logging** of the HTTP requests it makes and responses it receives during the test?
5. **Is it possible T2T has an internal error** it's masking as "Unknown error"? Can they check their server-side logs for our account?

## Verified Working Endpoints

All endpoints return correct CW-format JSON when tested with curl:

```bash
# Companyinfo (200, clean JSON, no cookies)
curl -s https://your-psa-domain/login/companyinfo/SoundPSA

# Boards (200, no auth required)
curl -s https://your-psa-domain/api/tier2tickets/v4_6_release/apis/3.0/service/boards

# Priorities (200, no auth required)
curl -s https://your-psa-domain/api/tier2tickets/v4_6_release/apis/3.0/service/priorities

# Boards with auth (200)
curl -s -u "SoundPSA+06a529e131a0:f4797db6b269c8807a259dd230aa0a47" \
  https://your-psa-domain/api/tier2tickets/v4_6_release/apis/3.0/service/boards

# Ticket creation with auth (201)
curl -s -X POST -u "SoundPSA+06a529e131a0:f4797db6b269c8807a259dd230aa0a47" \
  -H "Content-Type: application/json" \
  -d '{"summary":"Test","board":{"id":1},"company":{"id":1},"priority":{"id":3},"initialDescription":"Test"}' \
  https://your-psa-domain/api/tier2tickets/v4_6_release/apis/3.0/service/tickets
```
