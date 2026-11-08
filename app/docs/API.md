# Auvryon JSON API v1

Base URL (XAMPP): `http://localhost/auvryon/api/index.php?r=<route>`
All money is **simulated**. Responses are JSON (UTF-8). Errors: `{"error": "..."}` with status 401 (no/invalid token), 403, 404, 422 (validation / business rule), 429 (rate limit 120 req/min/user), 500.

## Authentication
`POST ?r=auth/login`  body `{"email":"tahsin@auvryon.test","password":"Demo@1234"}`
-> `{"token":"<64 hex>","expires_in":86400,"user":{...}}`
Send `Authorization: Bearer <token>` on every other call. Tokens are stored hashed and expire after 24 h. Login is rate limited and locks the account after 5 wrong passwords.

## Endpoints
| Method + route | Body / query | Result |
|---|---|---|
| `GET ?r=me` | | id, name, email, role |
| `GET ?r=wallets` | | BDT and USD balances, frozen flag |
| `GET ?r=transactions` | `q,type,status,dir(in/out),from,to,cur,limit(<=100),offset` | unified activity with `net_effect` read from the ledger |
| `GET ?r=ledger` | `currency=BDT/USD` | balance, `sum_of_entries` (must be equal), last 100 entries |
| `GET ?r=safe-to-spend` | | components and the explainable result |
| `GET ?r=forecast` | | 30-day series, shortfall (or null), assumptions |
| `POST ?r=transfers/preview` | `{to, amount, currency}` | recipient, fee, total, will_hold, cancel window, risk flags |
| `POST ?r=transfers` | header **`Idempotency-Key`** (8-64 chars, required); `{to, amount, currency, note, pin, password?}` | 201 `{ref,status,duplicate:false}`; repeating the same key returns 200 with `duplicate:true` and no second charge. `password` is required when the preview risk is `high`. status is `completed` or `held` (cancellable) or `approval_pending` |
| `POST ?r=transfers/cancel` | `{ref}` | cancels a held transfer inside the window (full refund incl. fee) |
| `POST ?r=requests` | `{to, amount, note}` | creates a payment request |
| `GET ?r=groups` | | groups of the user |
| `GET ?r=group-settlements` | `group_id` | optimised settlement transfers and the method used |
| `GET ?r=exchange-rate` | | USD/BDT demonstration rate, `as_of`, `simulated:true` |
| `GET ?r=reconciliation` | admin only | the eight ledger invariants with pass/fail |

## Example
```
curl -s -X POST "http://localhost/auvryon/api/index.php?r=auth/login" -H "Content-Type: application/json" -d "{\"email\":\"tahsin@auvryon.test\",\"password\":\"Demo@1234\"}"
curl -s -X POST "http://localhost/auvryon/api/index.php?r=transfers" -H "Authorization: Bearer <token>" -H "Idempotency-Key: demo-0001-abcdef" -H "Content-Type: application/json" -d "{\"to\":\"uchas@auvryon.test\",\"amount\":\"10\",\"pin\":\"1234\"}"
```
Only auth, transfer, role and rate-limit paths have automated tests (tests/http_test.py, HTTP-20); other endpoints are exercised manually.
