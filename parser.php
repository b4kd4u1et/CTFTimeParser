<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/CtftimeClient.php';
require_once __DIR__ . '/src/ContentSecurity.php';
require_once __DIR__ . '/src/Logger.php';

$config = require __DIR__ . '/config.php';

// ---------------------------------------------------------------------------
// Logger (simple file-based, no external dependencies)
// A09:2025 — rotates at 5 MB to prevent unbounded log growth.
// ---------------------------------------------------------------------------

$logFile = $config['log_file'];

$log = fn(string $level, string $msg) => log_msg($level, $msg, $logFile);

// ---------------------------------------------------------------------------
// Lock file — prevent overlapping cron runs
//
// A10:2025 — uses fopen('c') + flock(LOCK_EX|LOCK_NB) for atomic locking.
// This eliminates the TOCTOU race in the old file_exists()+touch() approach:
//   - fopen('c') creates or opens the file in a single syscall
//   - flock(LOCK_NB) returns false immediately if another process holds it
//   - The OS releases the lock automatically if the process dies, so stale
//     locks are impossible without any timeout heuristic.
// ---------------------------------------------------------------------------

$lockFile   = sys_get_temp_dir() . '/ctftimeparser.lock';
$lockHandle = fopen($lockFile, 'c');

if ($lockHandle === false || !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $log('warn', 'Another instance is already running. Exiting.');
    if (is_resource($lockHandle)) {
        fclose($lockHandle);
    }
    exit(0);
}

ftruncate($lockHandle, 0);
fwrite($lockHandle, (string) getmypid());
fflush($lockHandle);

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
    $total   = count($pending);
    $log('info', sprintf('%d new event(s) to process.', $total));

    $saved   = 0;
    $skipped = 0;
    $unsafe  = 0;

    // A10:2025 — index-based last-element check replaces next($pending).
    // next() manipulates the internal array pointer but foreach uses its own
    // independent iterator, so the old check was unreliable.
    foreach ($pending as $i => $eventId) {
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
        if ($cfg['sleep_between_requests'] > 0 && $i < $total - 1) {
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
    // Release the lock. flock() is released by the OS on process death,
    // so no stale lock can persist even if the script crashes.
    if (isset($lockHandle) && is_resource($lockHandle)) {
        flock($lockHandle, LOCK_UN);
        fclose($lockHandle);
        @unlink($lockFile);
    }
}

exit($exitCode);
