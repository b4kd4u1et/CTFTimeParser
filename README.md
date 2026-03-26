# CTFTimeParser

A lightweight PHP + MySQL parser that fetches upcoming CTF event announcements from [CTFTime](https://ctftime.org/) and stores them for publication in a Telegram supergroup topic.

---

## Features

- Fetches events via the [CTFTime public API](https://ctftime.org/api)
- Two-stage buffer pipeline — deduplication before fetching details
- Content security checks: XSS, SSTI, SQLi, SSRF, suspicious URLs, anomalous strings
- Unsafe events are stored with `is_safe=0` and held back from publication
- No external dependencies — pure PHP 8 + PDO + cURL
- Atomic lock file prevents overlapping cron runs
- File-based logging with automatic rotation at 5 MB

---

## Requirements

- PHP 8.0+
- MySQL 8.0+ (or MariaDB 10.5+)
- PHP extensions: `pdo_mysql`, `curl`, `mbstring`

---

## Setup

### 1. Create the database and user

```sql
CREATE DATABASE ctftimeparser CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Use a dedicated user with minimum required privileges, not root
CREATE USER 'ctfparser'@'localhost' IDENTIFIED BY 'strong_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON ctftimeparser.* TO 'ctfparser'@'localhost';
```

Apply the schema:

```bash
mysql -u ctfparser -p ctftimeparser < schema.sql
```

### 2. Configure

Copy the sample config and fill in your credentials:

```bash
cp config.php.sample config.php
```

Edit `config.php`:

```php
'db' => [
    'host' => 'localhost',
    'name' => 'ctftimeparser',
    'user' => 'ctfparser',
    'pass' => 'strong_password',
],
```

### 3. Run manually

```bash
php parser.php
```

### 4. Schedule via cron (every 6 hours)

```
0 */6 * * * /usr/bin/php /path/to/parser.php
```

---

## Project Structure

```
CTFTimeParser/
├── parser.php                 # Entry point (run via cron)
├── config.php                 # Database and parser settings (gitignored)
├── config.php.sample          # Configuration template
├── schema.sql                 # Database schema
├── src/
│   ├── CtftimeClient.php      # CTFTime API client (cURL)
│   ├── ContentSecurity.php    # Input sanitization and threat detection
│   ├── Database.php           # PDO wrapper, all queries
│   └── Formatter.php          # Telegram HTML message formatter
└── logs/
    └── parser.log             # Runtime log (auto-created, rotates at 5 MB)
```

---

## How It Works

The parser follows a three-step pipeline on each run:

**Step 1 — Collect IDs**
Fetches the event list from CTFTime API for the next 7 days and writes all event IDs into the `parser_buffer` table (`INSERT IGNORE`).

**Step 2 — Deduplicate**
Removes any IDs from `parser_buffer` that already exist in `ctf_events`, so only genuinely new events proceed.

**Step 3 — Fetch and store**
For each remaining ID, fetches the full event details, runs security checks, and inserts the sanitized record into `ctf_events`. Each request is followed by a 1-second pause to avoid hammering the API.

---

## Database Schema

### `parser_buffer`

| Column | Type | Description |
|---|---|---|
| `event_id` | INT UNSIGNED PK | CTFTime event ID |
| `created_at` | DATETIME | Row creation time |

### `ctf_events`

| Column | Type | Description |
|---|---|---|
| `id` | INT UNSIGNED PK | CTFTime event ID |
| `title` | VARCHAR(255) | Event name |
| `url` | VARCHAR(512) | Official event website |
| `ctftime_url` | VARCHAR(512) | CTFTime event page |
| `start_time` | DATETIME | Start (UTC) |
| `finish_time` | DATETIME | End (UTC) |
| `format` | VARCHAR(64) | Jeopardy / Attack-Defense / etc. |
| `weight` | FLOAT | CTFTime rating weight |
| `onsite` | TINYINT(1) | 1 = on-site event |
| `location` | VARCHAR(255) | City/country for on-site events |
| `description` | TEXT | Event description |
| `logo_url` | VARCHAR(512) | Event logo URL |
| `is_safe` | TINYINT(1) | 0 = flagged by security checks |
| `posted_at` | DATETIME | NULL = not yet published to Telegram |
| `created_at` | DATETIME | Row creation time |

Events with `is_safe = 0` are stored but **not published** until manually reviewed.
Events with `posted_at IS NULL` are pending publication.

---

## Security

Audited against **[OWASP Top 10:2025](https://owasp.org/Top10/2025/)**.

| OWASP | Threat | Defence |
|---|---|---|
| A01 | Broken Access Control / SSRF | `CURLOPT_FOLLOWLOCATION=false`; HTTPS-only to `ctftime.org`; credentials in URL rejected; private IP range check on literal IPs |
| A02 | Security Misconfiguration | Dedicated DB user with minimum privileges; `config.php` excluded from version control |
| A05 | Injection — SQLi | PDO prepared statements throughout; no string interpolation of user data; supplementary regex detection in content |
| A05 | Injection — SSTI | Regex detection of `{{ }}`, `{% %}`, `<% %>`, `${}`, `#{}` patterns in title/description |
| A05 | Injection — XSS | `strip_tags()` + `htmlspecialchars()` on all string fields before storage |
| A06 | Insecure Design | No blocking DNS resolution; URL scheme whitelist (`http`/`https` only); field length limits enforced |
| A08 | Data Integrity | Event ID overridden from request path, not response body; JSON depth limit prevents deep-parse attacks |
| A09 | Security Logging Failures | Structured log with level + timestamp; automatic rotation at 5 MB |
| A10 | Mishandling of Exceptional Conditions | Atomic lock via `fopen('c')+flock(LOCK_EX\|LOCK_NB)` — no TOCTOU race; OS releases lock on crash; index-based loop control replaces broken `next()` |

Events that fail any content check are stored with `is_safe=0` and withheld from publication pending manual review.

---

## Logging

Logs are written to `logs/parser.log` and automatically rotated to `parser.log.old` when the file exceeds 5 MB:

```
[2026-03-26 12:00:00] [INFO] Fetching event list [2026-03-26 – 2026-04-02]
[2026-03-26 12:00:01] [INFO] Received 14 event IDs from API.
[2026-03-26 12:00:01] [INFO] Buffer cleaned (removed already-known events).
[2026-03-26 12:00:01] [INFO] 3 new event(s) to process.
[2026-03-26 12:00:02] [INFO] Event #2345 saved: "CTF Example 2026" (safe=1)
[2026-03-26 12:00:03] [WARN] Event #2346: flagged as unsafe. Stored with is_safe=0.
[2026-03-26 12:00:04] [INFO] Done. Saved: 3 | Unsafe (stored): 1 | Skipped: 0
```

---

## Troubleshooting

**`Permission denied` writing to `logs/`**
```bash
chmod 755 logs/
```

**`PDO connection failed` / `Access denied for user`**
- Verify `config.php` credentials match your MySQL user
- Confirm the user has been granted privileges: `SHOW GRANTS FOR 'ctfparser'@'localhost';`

**`Could not create lock file`**
- Check that `sys_get_temp_dir()` (usually `/tmp`) is writable by the PHP process user

**No events appear after running**
- CTFTime API may return an empty list if no events are scheduled in the next 7 days — this is normal
- Check `logs/parser.log` for API errors or HTTP status codes other than 200

**`Another instance is already running`**
- A previous run is still in progress, or it crashed while holding the lock
- Because `flock()` is used, the lock releases automatically when the process exits — wait for the current run to finish or verify no `php parser.php` process is running
