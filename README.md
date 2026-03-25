# CTFTimeParser

A lightweight PHP + MySQL parser that fetches upcoming CTF event announcements from [CTFTime](https://ctftime.org/) and stores them for publication in a Telegram supergroup topic.

---

## Features

- Fetches events via the [CTFTime public API](https://ctftime.org/api)
- Two-stage buffer pipeline — deduplication before fetching details
- Content security checks: XSS, SSTI, SQLi, SSRF, suspicious URLs, anomalous strings
- Unsafe events are stored with `is_safe=0` and held back from publication
- No external dependencies — pure PHP 8 + PDO + cURL
- Lock file prevents overlapping cron runs
- Simple file-based logging

---

## Requirements

- PHP 8.0+
- MySQL 8.0+ (or MariaDB 10.5+)
- PHP extensions: `pdo_mysql`, `curl`, `mbstring`

---

## Setup

### 1. Create the database

```bash
mysql -u root -p -e "CREATE DATABASE ctftimeparser CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
mysql -u root -p ctftimeparser < schema.sql
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
    'user' => 'your_user',
    'pass' => 'your_password',
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
├── config.php                 # Database and parser settings
├── schema.sql                 # Database schema
├── src/
│   ├── CtftimeClient.php      # CTFTime API client (cURL)
│   ├── Database.php           # PDO wrapper, all queries
│   └── ContentSecurity.php    # Input sanitization and threat detection
└── logs/
    └── parser.log             # Runtime log (auto-created)
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

| Threat | Defence |
|---|---|
| XSS | `strip_tags()` + `htmlspecialchars()` on all string fields |
| SQLi | PDO prepared statements; regex pattern detection in content |
| SSTI | Regex detection of `{{ }}`, `{% %}`, `<% %>`, `${}`, `#{}` |
| SSRF | `CURLOPT_FOLLOWLOCATION=false`; HTTPS-only; domain locked to `ctftime.org`; private IP range check on resolved hosts |
| Suspicious URLs | `FILTER_VALIDATE_URL`; `http`/`https` only; credentials in URL blocked; internal host detection |
| Anomalous strings | Null-byte check; UTF-8 encoding validation; field length limits |

---

## Logging

Logs are written to `logs/parser.log`:

```
[2025-03-25 12:00:00] [INFO] Fetching event list [2025-03-25 – 2025-04-01]
[2025-03-25 12:00:01] [INFO] Received 14 event IDs from API.
[2025-03-25 12:00:01] [INFO] Buffer cleaned (removed already-known events).
[2025-03-25 12:00:01] [INFO] 3 new event(s) to process.
[2025-03-25 12:00:02] [INFO] Event #2345 saved: "CTF Example 2025" (safe=1)
[2025-03-25 12:00:03] [WARN] Event #2346: flagged as unsafe. Stored with is_safe=0.
[2025-03-25 12:00:04] [INFO] Done. Saved: 3 | Unsafe (stored): 1 | Skipped: 0
```
