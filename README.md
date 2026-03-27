# CTFTimeParser

> [Қазақша](#қазақша) · [Русский](#русский) · [English](#english)

---

## Қазақша

**CTFTimeParser** — [CTFTime](https://ctftime.org/) сайтынан алдағы CTF жарыстары туралы хабарландыруларды жинап, оларды Telegram супертопқа жариялау үшін MySQL дерекқорына сақтайтын жеңіл PHP + MySQL парсері.

### Мүмкіндіктер

- CTFTime API арқылы оқиғаларды автоматты жинау
- Екі сатылы буфер: деректер қайталанбауы үшін алдын ала сүзгіден өтеді
- XSS, SSTI, SQLi, SSRF және күдікті URL-дерге қарсы мазмұн қауіпсіздігі тексерулері
- Қауіпті оқиғалар `is_safe=0` белгісімен сақталып, жариялаудан ұсталады
- Сыртқы тәуелділіктер жоқ — таза PHP 8 + PDO + cURL
- Атомдық блокировка файлы cron-процестерінің қабаттасуын болдырмайды
- Файлдық логтау, 5 МБ-тан асқанда автоматты ротация

### Талаптар

- PHP 8.0+
- MySQL 8.0+ (немесе MariaDB 10.5+)
- PHP кеңейтімдері: `pdo_mysql`, `curl`, `mbstring`

### Орнату

#### 1. Дерекқор және пайдаланушы жасау

```sql
CREATE DATABASE ctftimeparser CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'ctfparser'@'localhost' IDENTIFIED BY 'strong_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON ctftimeparser.* TO 'ctfparser'@'localhost';
```

Схеманы қолдану:

```bash
mysql -u ctfparser -p ctftimeparser < schema.sql
```

#### 2. Конфигурация

```bash
cp config.php.sample config.php
```

`config.php` файлын өңдеңіз:

```php
'db' => [
    'host' => 'localhost',
    'name' => 'ctftimeparser',
    'user' => 'ctfparser',
    'pass' => 'strong_password',
],
```

#### 3. Қолмен іске қосу

```bash
php parser.php
```

#### 4. Cron арқылы жоспарлау (әр 6 сағат сайын)

```
0 */6 * * * /usr/bin/php /path/to/parser.php
```

### Жоба құрылымы

```
CTFTimeParser/
├── parser.php                 # Кіру нүктесі (cron арқылы іске қосылады)
├── config.php                 # Дерекқор және парсер параметрлері (gitignored)
├── config.php.sample          # Конфигурация үлгісі
├── schema.sql                 # Дерекқор схемасы
├── src/
│   ├── CtftimeClient.php      # CTFTime API клиенті (cURL)
│   ├── ContentSecurity.php    # Мазмұн қауіпсіздігі тексерулері
│   ├── Database.php           # PDO орауышы, барлық сұраулар
│   └── Formatter.php          # Telegram HTML хабар форматтаушы
└── logs/
    └── parser.log             # Жұмыс логы (5 МБ кезінде ротация)
```

### Жалпы алгоритм

```
Cron (әр 6 сағат)
    │
    ▼
parser.php іске қосылады
    │
    ├─► Блокировка тексерісі (/tmp/ctftimeparser.lock)
    │       Басқа процесс жұмыс жасаса → дереу шығу
    │
    ├─► Инициализация: config.php → Database (PDO) + CtftimeClient (cURL)
    │
    ├─► 1-қадам: ID жинау
    │       CtftimeClient → CTFTime API (келесі N күн)
    │       Алынған ID-лар → parser_buffer (INSERT IGNORE)
    │
    ├─► 2-қадам: Дедупликация
    │       ctf_events-те бар ID-ларды parser_buffer-дан жою
    │
    ├─► 3-қадам: Деректерді жүктеп сақтау (әр жаңа ID үшін)
    │       │
    │       ├─► CtftimeClient → толық оқиға деректері (JSON)
    │       │
    │       ├─► ID жолдан алынады (response body-дан емес) — спуфинг қорғанысы
    │       │
    │       ├─► ContentSecurity::sanitize()
    │       │       strip_tags + htmlspecialchars (XSS)
    │       │       SSTI/SQLi үлгілерін тексеру
    │       │       URL схемасы + SSRF тексерісі
    │       │       Өріс ұзындығы шектеулері
    │       │
    │       ├─► Санитизация сәтсіз → буферден жою, өткізіп жіберу
    │       │
    │       ├─► is_safe=0 → ctf_events-ке сақтау (жарияланбайды)
    │       │
    │       ├─► is_safe=1 → ctf_events-ке сақтау (жариялауға дайын)
    │       │
    │       └─► Сұраулар арасында 1 секунд үзіліс
    │
    ├─► Лог жазылады (5 МБ-тан асса — ротация)
    │
    └─► Блокировка босатылады (бұзылу болса — OS автоматты босатады)

Telegram боты (сыртқы)
    │
    └─► ctf_events WHERE is_safe=1 AND posted_at IS NULL
            │
            └─► Formatter::event() → Telegram HTML хабары
                    Тақырып, күндер, формат/салмақ,
                    орналасу (онлайн/очно), сипаттама үзіндісі, сілтемелер
                    → Telegram-ға жіберу → posted_at белгісі қойылады
```

### Ақаулықтарды жою

| Қате | Шешім |
|------|-------|
| `Permission denied` (logs/) | `chmod 755 logs/` |
| `PDO connection failed` | `config.php` деректерін тексеріңіз, пайдаланушы артықшылықтарын растаңыз |
| `Could not create lock file` | `/tmp` каталогының жазу рұқсатын тексеріңіз |
| Оқиғалар пайда болмайды | API-дан бос жауап қалыпты (7 күнде оқиға жоқ). Логтарды тексеріңіз |
| `Another instance is already running` | Алдыңғы процесс аяқталғанша күтіңіз немесе `php parser.php` процесі жоқ екенін тексеріңіз |

---

## Русский

**CTFTimeParser** — лёгкий PHP + MySQL парсер, который собирает объявления о предстоящих CTF-соревнованиях с [CTFTime](https://ctftime.org/) и сохраняет их в базу данных для публикации в Telegram-супергруппе.

### Возможности

- Автоматический сбор событий через CTFTime API
- Двухэтапный буфер: дедупликация перед загрузкой деталей
- Проверки безопасности контента: XSS, SSTI, SQLi, SSRF, подозрительные URL
- Небезопасные события сохраняются с флагом `is_safe=0` и не публикуются
- Без внешних зависимостей — чистый PHP 8 + PDO + cURL
- Атомарная блокировка предотвращает параллельный запуск cron-задач
- Файловое логирование с автоматической ротацией при 5 МБ

### Требования

- PHP 8.0+
- MySQL 8.0+ (или MariaDB 10.5+)
- PHP-расширения: `pdo_mysql`, `curl`, `mbstring`

### Установка

#### 1. Создание базы данных и пользователя

```sql
CREATE DATABASE ctftimeparser CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Выделенный пользователь с минимальными привилегиями, не root
CREATE USER 'ctfparser'@'localhost' IDENTIFIED BY 'strong_password';
GRANT SELECT, INSERT, UPDATE, DELETE ON ctftimeparser.* TO 'ctfparser'@'localhost';
```

Применить схему:

```bash
mysql -u ctfparser -p ctftimeparser < schema.sql
```

#### 2. Конфигурация

```bash
cp config.php.sample config.php
```

Отредактируйте `config.php`:

```php
'db' => [
    'host' => 'localhost',
    'name' => 'ctftimeparser',
    'user' => 'ctfparser',
    'pass' => 'strong_password',
],
```

#### 3. Ручной запуск

```bash
php parser.php
```

#### 4. Запуск через cron (каждые 6 часов)

```
0 */6 * * * /usr/bin/php /path/to/parser.php
```

### Структура проекта

```
CTFTimeParser/
├── parser.php                 # Точка входа (запускается через cron)
├── config.php                 # Настройки БД и парсера (gitignored)
├── config.php.sample          # Шаблон конфигурации
├── schema.sql                 # Схема базы данных
├── src/
│   ├── CtftimeClient.php      # Клиент CTFTime API (cURL)
│   ├── ContentSecurity.php    # Проверки безопасности контента
│   ├── Database.php           # Обёртка PDO, все запросы
│   └── Formatter.php          # Форматтер Telegram HTML-сообщений
└── logs/
    └── parser.log             # Лог работы (ротация при 5 МБ)
```

### Общий алгоритм

```
Cron (каждые 6 часов)
    │
    ▼
parser.php запускается
    │
    ├─► Проверка блокировки (/tmp/ctftimeparser.lock)
    │       Другой процесс работает → немедленный выход
    │
    ├─► Инициализация: config.php → Database (PDO) + CtftimeClient (cURL)
    │
    ├─► Шаг 1: Сбор ID
    │       CtftimeClient → CTFTime API (следующие N дней)
    │       Полученные ID → parser_buffer (INSERT IGNORE)
    │
    ├─► Шаг 2: Дедупликация
    │       Удалить из parser_buffer ID, уже есть в ctf_events
    │
    ├─► Шаг 3: Загрузка и сохранение (для каждого нового ID)
    │       │
    │       ├─► CtftimeClient → полные данные события (JSON)
    │       │
    │       ├─► ID берётся из пути запроса (не из тела ответа) — защита от спуфинга
    │       │
    │       ├─► ContentSecurity::sanitize()
    │       │       strip_tags + htmlspecialchars (XSS)
    │       │       Проверка паттернов SSTI/SQLi
    │       │       Валидация схемы URL + проверка SSRF
    │       │       Ограничения длины полей
    │       │
    │       ├─► Санитизация не прошла → удалить из буфера, пропустить
    │       │
    │       ├─► is_safe=0 → сохранить в ctf_events (не публикуется)
    │       │
    │       ├─► is_safe=1 → сохранить в ctf_events (готово к публикации)
    │       │
    │       └─► Пауза 1 секунда между запросами
    │
    ├─► Запись в лог (ротация при превышении 5 МБ)
    │
    └─► Блокировка снимается (при краше — OS снимает автоматически)

Telegram-бот (внешний)
    │
    └─► ctf_events WHERE is_safe=1 AND posted_at IS NULL
            │
            └─► Formatter::event() → HTML-сообщение для Telegram
                    Название, даты, формат/вес,
                    место проведения (онлайн/очно), превью описания, ссылки
                    → отправка в Telegram → проставляется posted_at
```

### Схема базы данных

#### `parser_buffer`

| Столбец | Тип | Описание |
|---------|-----|----------|
| `event_id` | INT UNSIGNED PK | ID события CTFTime |
| `created_at` | DATETIME | Время создания записи |

#### `ctf_events`

| Столбец | Тип | Описание |
|---------|-----|----------|
| `id` | INT UNSIGNED PK | ID события CTFTime |
| `title` | VARCHAR(255) | Название события |
| `url` | VARCHAR(512) | Официальный сайт события |
| `ctftime_url` | VARCHAR(512) | Страница события на CTFTime |
| `start_time` | DATETIME | Начало (UTC) |
| `finish_time` | DATETIME | Конец (UTC) |
| `format` | VARCHAR(64) | Jeopardy / Attack-Defense / и др. |
| `weight` | FLOAT | Рейтинговый вес CTFTime |
| `onsite` | TINYINT(1) | 1 = очное мероприятие |
| `location` | VARCHAR(255) | Город/страна для очных событий |
| `description` | TEXT | Описание события |
| `logo_url` | VARCHAR(512) | URL логотипа |
| `is_safe` | TINYINT(1) | 0 = помечено проверкой безопасности |
| `posted_at` | DATETIME | NULL = ещё не опубликовано в Telegram |
| `created_at` | DATETIME | Время создания записи |

### Безопасность

Аудит по **[OWASP Top 10:2025](https://owasp.org/Top10/2025/)**.

| OWASP | Угроза | Защита |
|-------|--------|--------|
| A01 | SSRF | `CURLOPT_FOLLOWLOCATION=false`; только HTTPS к `ctftime.org`; проверка приватных IP-диапазонов |
| A02 | Security Misconfiguration | Выделенный пользователь БД с минимальными привилегиями; `config.php` исключён из VCS |
| A05 | SQLi | PDO prepared statements повсюду; никакой интерполяции пользовательских данных |
| A05 | SSTI | Regex-детекция паттернов `{{ }}`, `{% %}`, `<% %>`, `${}`, `#{}` |
| A05 | XSS | `strip_tags()` + `htmlspecialchars()` на всех строковых полях перед сохранением |
| A06 | Insecure Design | Белый список схем URL (`http`/`https`); ограничения длины полей |
| A08 | Data Integrity | ID события берётся из пути запроса, а не из тела ответа; лимит глубины JSON |
| A09 | Security Logging | Структурированный лог с уровнем + временной меткой; ротация при 5 МБ |
| A10 | Race Condition | Атомарная блокировка `fopen('c')+flock(LOCK_EX\|LOCK_NB)`; OS освобождает блокировку при краше |

### Логирование

```
[2026-03-26 12:00:00] [INFO] Fetching event list [2026-03-26 – 2026-04-02]
[2026-03-26 12:00:01] [INFO] Received 14 event IDs from API.
[2026-03-26 12:00:01] [INFO] Buffer cleaned (removed already-known events).
[2026-03-26 12:00:01] [INFO] 3 new event(s) to process.
[2026-03-26 12:00:02] [INFO] Event #2345 saved: "CTF Example 2026" (safe=1)
[2026-03-26 12:00:03] [WARN] Event #2346: flagged as unsafe. Stored with is_safe=0.
[2026-03-26 12:00:04] [INFO] Done. Saved: 3 | Unsafe (stored): 1 | Skipped: 0
```

### Устранение неполадок

| Ошибка | Решение |
|--------|---------|
| `Permission denied` (logs/) | `chmod 755 logs/` |
| `PDO connection failed` | Проверьте `config.php`, убедитесь в правах пользователя БД |
| `Could not create lock file` | Проверьте права на запись в `/tmp` |
| События не появляются | Пустой ответ API — норма (нет событий на 7 дней). Проверьте логи |
| `Another instance is already running` | Дождитесь завершения предыдущего процесса или убедитесь, что `php parser.php` не запущен |

---

## English

**CTFTimeParser** is a lightweight PHP + MySQL parser that fetches upcoming CTF event announcements from [CTFTime](https://ctftime.org/) and stores them for publication in a Telegram supergroup topic.

### Features

- Fetches events via the CTFTime public API
- Two-stage buffer pipeline — deduplication before fetching details
- Content security checks: XSS, SSTI, SQLi, SSRF, suspicious URLs
- Unsafe events are stored with `is_safe=0` and held back from publication
- **Weekly digest** every Monday at 07:00 — compact list of all events for the next 14 days
- **Daily updates** every day at 07:00 — individual full-detail posts for new events
- No external dependencies — pure PHP 8 + PDO + cURL
- Atomic lock files prevent overlapping cron runs
- File-based logging with automatic rotation at 5 MB

### Requirements

- PHP 8.0+
- MySQL 8.0+ (or MariaDB 10.5+)
- PHP extensions: `pdo_mysql`, `curl`, `mbstring`

### Setup

#### 1. Create the database and user

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

#### 2. Configure

```bash
cp config.php.sample config.php
```

Fill in `config.php` with your database credentials and Telegram settings:

```php
'db' => [
    'user' => 'ctfparser',
    'pass' => 'strong_password',
    ...
],
'telegram' => [
    'bot_token' => '123456:ABC-your-token',
    'chat_id'   => '-1001234567890',
    'thread_id' => 42,
    ...
],
```

#### 3. Run manually

```bash
php parser.php
```

#### 4. Schedule via cron (every 6 hours)

```
# Fetch new events from CTFTime every 6 hours
0 */6 * * * /usr/bin/php /path/to/parser.php

# Publish to Telegram every day at 07:00
0 7   * * * /usr/bin/php /path/to/publisher.php
```

### Project Structure

```
CTFTimeParser/
├── parser.php                 # CTFTime → MySQL pipeline (run via cron)
├── publisher.php              # MySQL → Telegram publisher (run via cron)
├── config.php                 # Credentials and settings (gitignored)
├── config.php.sample          # Configuration template
├── schema.sql                 # Database schema
├── src/
│   ├── CtftimeClient.php      # CTFTime API client (cURL)
│   ├── ContentSecurity.php    # Input sanitization and threat detection
│   ├── Database.php           # PDO wrapper, all queries
│   ├── Formatter.php          # Telegram HTML message formatter
│   └── TelegramBot.php        # Telegram Bot API client (cURL)
└── logs/
    ├── parser.log             # Parser log (auto-created, rotates at 5 MB)
    └── publisher.log          # Publisher log (auto-created, rotates at 5 MB)
```

### Overall Algorithm

```
Cron (every 6 hours)
    │
    ▼
parser.php runs
    │
    ├─► Lock check (/tmp/ctftimeparser.lock)
    │       Another instance running → exit immediately
    │
    ├─► Bootstrap: config.php → Database (PDO) + CtftimeClient (cURL)
    │
    ├─► Step 1: Collect IDs
    │       CtftimeClient → CTFTime API (next N days)
    │       Received IDs → parser_buffer (INSERT IGNORE)
    │
    ├─► Step 2: Deduplicate
    │       Remove from parser_buffer any IDs already in ctf_events
    │
    ├─► Step 3: Fetch and store (for each new ID)
    │       │
    │       ├─► CtftimeClient → full event details (JSON)
    │       │
    │       ├─► Event ID taken from request path (not response body) — anti-spoofing
    │       │
    │       ├─► ContentSecurity::sanitize()
    │       │       strip_tags + htmlspecialchars (XSS)
    │       │       SSTI / SQLi pattern detection
    │       │       URL scheme validation + SSRF checks
    │       │       Field length limits
    │       │
    │       ├─► Sanitization failed → delete from buffer, skip
    │       │
    │       ├─► is_safe=0 → store in ctf_events (withheld from publication)
    │       │
    │       ├─► is_safe=1 → store in ctf_events (ready to publish)
    │       │
    │       └─► 1-second pause between requests
    │
    ├─► Write to log (rotate when file exceeds 5 MB)
    │
    └─► Lock released (OS releases automatically on crash)

Telegram bot (external)
    │
    └─► ctf_events WHERE is_safe=1 AND posted_at IS NULL
            │
            └─► Formatter::event() → Telegram HTML message
                    Title, dates, format/weight,
                    venue (online/on-site), description preview, links
                    → send to Telegram → posted_at is set
```

### Database Schema

#### `parser_buffer`

| Column | Type | Description |
|--------|------|-------------|
| `event_id` | INT UNSIGNED PK | CTFTime event ID |
| `created_at` | DATETIME | Row creation time |

#### `ctf_events`

| Column | Type | Description |
|--------|------|-------------|
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

### Security

Audited against **[OWASP Top 10:2025](https://owasp.org/Top10/2025/)**.

| OWASP | Threat | Defence |
|-------|--------|---------|
| A01 | SSRF | `CURLOPT_FOLLOWLOCATION=false`; HTTPS-only to `ctftime.org`; private IP range check |
| A02 | Security Misconfiguration | Dedicated DB user with minimum privileges; `config.php` excluded from VCS |
| A05 | SQLi | PDO prepared statements throughout; no string interpolation of user data |
| A05 | SSTI | Regex detection of `{{ }}`, `{% %}`, `<% %>`, `${}`, `#{}` patterns |
| A05 | XSS | `strip_tags()` + `htmlspecialchars()` on all string fields before storage |
| A06 | Insecure Design | URL scheme whitelist (`http`/`https` only); field length limits enforced |
| A08 | Data Integrity | Event ID taken from request path, not response body; JSON depth limit |
| A09 | Security Logging | Structured log with level + timestamp; automatic rotation at 5 MB |
| A10 | Race Condition | Atomic lock via `fopen('c')+flock(LOCK_EX\|LOCK_NB)`; OS releases lock on crash |

### Logging

**`logs/parser.log`**
```
[2026-03-26 12:00:00] [INFO] Fetching event list [2026-03-26 – 2026-04-02]
[2026-03-26 12:00:01] [INFO] Received 14 event IDs from API.
[2026-03-26 12:00:01] [INFO] Buffer cleaned (removed already-known events).
[2026-03-26 12:00:01] [INFO] 3 new event(s) to process.
[2026-03-26 12:00:02] [INFO] Event #2345 saved: "SomeCTF 2026" (safe=1)
[2026-03-26 12:00:04] [INFO] Done. Saved: 3 | Unsafe (stored): 0 | Skipped: 0
```

**`logs/publisher.log`**
```
[2026-03-30 07:00:00] [INFO] Daily update: 3 event(s) to publish.
[2026-03-30 07:00:01] [INFO] Published event #2345: "SomeCTF 2026"
[2026-03-30 07:00:03] [INFO] Published event #2346: "AnotherCTF 2026"
[2026-03-30 07:00:05] [INFO] Daily update done. Sent: 3 | Failed: 0
```

### Troubleshooting

| Error | Fix |
|-------|-----|
| `Permission denied` (logs/) | `chmod 755 logs/` |
| `PDO connection failed` | Verify `config.php` credentials; check user grants |
| `Could not create lock file` | Ensure `/tmp` is writable by the PHP process user |
| No events appear | Empty API response is normal if no events in next 7 days. Check logs |
| `Another instance is already running` | Wait for current run to finish or verify no `php parser.php` process is running |
