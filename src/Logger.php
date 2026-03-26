<?php

declare(strict_types=1);

function log_msg(string $level, string $message, string $logFile): void
{
    // Rotate when log exceeds 5 MB — previous log kept as .old (A09:2025)
    if (file_exists($logFile) && filesize($logFile) > 5 * 1024 * 1024) {
        rename($logFile, $logFile . '.old');
    }

    $line = sprintf('[%s] [%s] %s' . PHP_EOL, gmdate('Y-m-d H:i:s'), strtoupper($level), $message);
    file_put_contents($logFile, $line, FILE_APPEND | LOCK_EX);
}
