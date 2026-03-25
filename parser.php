<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/CtftimeClient.php';
require_once __DIR__ . '/src/ContentSecurity.php';

$config = require __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Logger (simple file-based, no external dependencies)
// ---------------------------------------------------------------------------

$logFile = $config['log_file'];

function log_msg(string $level, string $message, string $logFile): void
{
    $line = sprintf('[%s] [%s] %s' . PHP_EOL, date('Y-m-d H:i:s'), strtoupper($level), $message);
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}

$log = fn(string $level, string $msg) => log_msg($level, $msg, $logFile);

// ---------------------------------------------------------------------------
// Lock file — prevent overlapping cron runs
// ---------------------------------------------------------------------------

$lockFile    = sys_get_temp_dir() . '/ctftimeparser.lock';
$lockTimeout = 3600; // 1 hour — if older than this, stale lock

if (file_exists($lockFile) && (time() - (int) filemtime($lockFile)) < $lockTimeout) {
    $log('warn', 'Another instance is already running. Exiting.');
    exit(0);
}

if (file_put_contents($lockFile, (string) getmypid(), LOCK_EX) === false) {
    $log('error', 'Could not create lock file. Exiting.');
    exit(1);
}

// ---------------------------------------------------------------------------
// Main
// ---------------------------------------------------------------------------

$exitCode = 0;

try {
    $db     = new Database($config['db']);
    $client = new CtftimeClient($config['parser']['request_timeout']);
    $cfg    = $config['parser'];

    // -----------------------------------------------------------------------
    // Step 1 — Collect event IDs into parser_buffer
    // -----------------------------------------------------------------------

    $now    = time();
    $finish = $now + ($cfg['days_ahead'] * 86400);

    $log('info', sprintf('Fetching event list [%s – %s]', date('Y-m-d', $now), date('Y-m-d', $finish)));

    $ids = $client->fetchEventIds($now, $finish, $cfg['events_limit']);

    if (empty($ids)) {
        $log('info', 'No events returned from CTFTime API.');
    } else {
        $log('info', sprintf('Received %d event IDs from API.', count($ids)));
        $db->insertBuffer($ids);
    }

    // -----------------------------------------------------------------------
    // Step 2 — Remove IDs already present in ctf_events
    // -----------------------------------------------------------------------

    $db->cleanBuffer();
    $log('info', 'Buffer cleaned (removed already-known events).');

    // -----------------------------------------------------------------------
    // Step 3 — Fetch details for remaining IDs and store in ctf_events
    // -----------------------------------------------------------------------

    $pending = $db->getBufferIds();
    $log('info', sprintf('%d new event(s) to process.', count($pending)));

    $saved   = 0;
    $skipped = 0;
    $unsafe  = 0;

    foreach ($pending as $eventId) {
        $log('info', sprintf('Fetching details for event #%d ...', $eventId));

        $raw = $client->fetchEventDetail($eventId);

        if ($raw === null) {
            $log('warn', sprintf('Event #%d: failed to fetch details. Removing from buffer.', $eventId));
            $db->deleteFromBuffer($eventId);
            $skipped++;
            continue;
        }

        // Force event id from URL path to avoid spoofing via response body
        $raw['id'] = $eventId;

        // Build CTFTime URL
        if (empty($raw['ctftime_url'])) {
            $raw['ctftime_url'] = 'https://ctftime.org/event/' . $eventId;
        }

        $sanitized = ContentSecurity::sanitize($raw);

        if ($sanitized === null) {
            $log('warn', sprintf('Event #%d: failed sanitization (invalid data). Skipping.', $eventId));
            $db->deleteFromBuffer($eventId);
            $skipped++;
            continue;
        }

        if (!$sanitized['is_safe']) {
            $log('warn', sprintf('Event #%d: flagged as unsafe. Stored with is_safe=0.', $eventId));
            $unsafe++;
        }

        $db->insertEvent($sanitized);
        $db->deleteFromBuffer($eventId);
        $saved++;

        $log('info', sprintf(
            'Event #%d saved: "%s" (safe=%d)',
            $eventId,
            $sanitized['title'],
            (int) $sanitized['is_safe']
        ));

        // Pause between requests — be polite to CTFTime
        if ($cfg['sleep_between_requests'] > 0 && next($pending) !== false) {
            sleep($cfg['sleep_between_requests']);
        }
    }

    $log('info', sprintf(
        'Done. Saved: %d | Unsafe (stored): %d | Skipped: %d',
        $saved,
        $unsafe,
        $skipped
    ));

} catch (PDOException $e) {
    $log('error', 'Database error: ' . $e->getMessage());
    $exitCode = 1;
} catch (Throwable $e) {
    $log('error', 'Unexpected error: ' . $e->getMessage());
    $exitCode = 1;
} finally {
    // Always release the lock
    if (file_exists($lockFile)) {
        unlink($lockFile);
    }
}

exit($exitCode);
