# Auvryon - Intelligent Digital Finance & Payment Ecosystem

NSU CSE311L project (Faculty Tns1, Section 3, Team 1, submission 22 October 2026).
Stack: XAMPP (Apache, PHP 8.1+, MariaDB/MySQL), PDO prepared statements, HTML/CSS/vanilla JS. Database: `auvryon_db`.

> **Everything is simulated.** No real money, bank deposits, exchange rates, cards, biometrics, blockchain or smart contracts are involved.
> A responsive website is not a native mobile app; the JSON API (`api/index.php`, docs in `docs/API.md`) is provided so a future mobile client can reuse the same services.

## Quick start on XAMPP
1. Copy this folder to `C:\xampp\htdocs\auvryon` (so `C:\xampp\htdocs\auvryon\index.php` exists).
2. XAMPP Control Panel: start **Apache** and **MySQL**.
3. phpMyAdmin (`http://localhost/phpmyadmin`) > New > database `auvryon_db`, collation `utf8mb4_unicode_ci` > select it > **Import** > `sql/auvryon_db.sql`.
   (Alternative: import `sql/schema.sql`, then `sql/base_data.sql`, then run `php bin/seed.php` for demo data.)
4. `config.php` already holds the XAMPP defaults (user `root`, empty password). Edit it if your MySQL has a password. Change `app_secret` before sharing.
5. Open **http://localhost/auvryon/**

Demo accounts (password `Demo@1234`, PIN `1234`): `tahsin@`, `uchas@`, `tafsir@`, `adnan@`, `merchant@`, `freelancer@`, `student@`, `admin@` + `auvryon.test`.
The full Setup and Testing Guide (PDF) has the demonstration script, troubleshooting and the executed test evidence.

## Layout
| Path | Purpose |
|---|---|
| `index.php` | Front controller: sessions, CSRF, security headers, routing |
| `pages/` | One file per screen (dashboard, send, wallet, activity, groups, escrow, invoices, ...) |
| `lib/ledger.php` | Double-entry engine: the only code that changes balances (`Ledger::post`), reconciliation |
| `lib/payments.php` | `Pay` (transfers, holds, cancel, refunds, bills), `Approvals`, `Pockets`, `Fx` |
| `lib/features.php` | `Groups`, `Ious`, `Escrow`, `Disputes`, `Invoices`, `Cash`, `Tokens`, `Offline`, `Rules` |
| `lib/auth.php`, `lib/core.php` | Authentication, sessions/devices, PDO, CSRF, rate limiting, audit |
| `lib/activity.php`, `lib/pdf.php`, `lib/ui.php`, `lib/i18n.php` | Unified activity + exports, PDF writer, design-system helpers/charts, English/Bangla strings |
| `api/index.php` | JSON API v1 (bearer token) |
| `sql/` | `schema.sql`, `base_data.sql`, `auvryon_db.sql` (full export), `useful_queries.sql` |
| `bin/` | `seed.php`, `maintenance.php` (cron), `reconcile.php` |
| `tests/` | `run_tests.php` + `worker.php` (121 service tests, parallel processes), `http_test.py` (27 HTTP tests), result files |
| `assets/` | `css/auvryon.css` (design tokens + components), `js/app.js`, bundled `js/qrcode.js` (MIT) |
| `docs/` | ERD images, `API.md` |

## Key design rules
* Amounts are `DECIMAL(18,2)`; PHP math uses integer cents.
* Every balance change is a balanced double-entry posting under row locks inside one DB transaction; wallets can never go negative (CHECK + code).
* Money-moving requests carry an idempotency key; duplicates and parallel duplicates charge once.
* First-time or high-value transfers are held in a `HOLD` account for a configurable window and can be cancelled for a full refund.
* Escrow, held transfers, fees, bills and FX use separate ledger accounts. `php bin/reconcile.php` prints eight invariants.

## Running the tests
```
mysql -uroot -e "CREATE DATABASE auvryon_test CHARACTER SET utf8mb4"
mysql -uroot auvryon_test < sql/schema.sql && mysql -uroot auvryon_test < sql/base_data.sql
AUVRYON_DB=auvryon_test php tests/run_tests.php      # wipes the target database; refuses auvryon_db
python3 tests/http_test.py http://localhost/auvryon/  # needs: pip install requests
```

## Honest limitations (details in the Setup and Testing Guide)
Not run on XAMPP itself (tests ran on PHP 8.3 + MariaDB 10.11 with PHP's built-in server); layouts not visually checked in a real browser;
Bangla coverage is partial and not native-reviewed; simulated biometrics have no automated test; no payment provider is integrated;
background jobs run on page loads or `bin/maintenance.php`; notifications are in-app only.
