<?php

declare(strict_types=1);

class Database
{
    private PDO $pdo;

    public function __construct(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            $config['port'] ?? 3306,
            $config['name'],
            $config['charset'] ?? 'utf8mb4'
        );

        $this->pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
    }

    /**
     * Insert new event IDs into parser_buffer in a single batch query.
     * Uses INSERT IGNORE so duplicates are silently skipped.
     *
     * A single multi-row INSERT avoids N separate round-trips to the database.
     * Placeholders are generated from count($ids) — not from user input — and
     * all values are cast to int before binding, so there is no injection surface.
     *
     * @param int[] $ids
     */
    public function insertBuffer(array $ids): void
    {
        if (empty($ids)) {
            return;
        }

        $placeholders = implode(',', array_fill(0, count($ids), '(?)'));
        $stmt = $this->pdo->prepare(
            "INSERT IGNORE INTO `parser_buffer` (`event_id`) VALUES {$placeholders}"
        );
        $stmt->execute(array_map('intval', $ids));
    }

    /**
     * Remove IDs from parser_buffer that already exist in ctf_events.
     */
    public function cleanBuffer(): void
    {
        $this->pdo->exec(
            'DELETE FROM `parser_buffer`
             WHERE `event_id` IN (SELECT `id` FROM `ctf_events`)'
        );
    }

    /**
     * Return all event IDs remaining in parser_buffer.
     *
     * @return int[]
     */
    public function getBufferIds(): array
    {
        $stmt = $this->pdo->query(
            'SELECT `event_id` FROM `parser_buffer` ORDER BY `event_id` ASC'
        );

        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Insert a sanitized event into ctf_events.
     */
    public function insertEvent(array $data): void
    {
        $sql = '
            INSERT INTO `ctf_events`
                (`id`, `title`, `url`, `ctftime_url`, `start_time`, `finish_time`,
                 `format`, `weight`, `onsite`, `location`, `description`, `logo_url`, `is_safe`)
            VALUES
                (:id, :title, :url, :ctftime_url, :start_time, :finish_time,
                 :format, :weight, :onsite, :location, :description, :logo_url, :is_safe)
            ON DUPLICATE KEY UPDATE `id` = `id`
        ';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            ':id'          => (int) $data['id'],
            ':title'       => $data['title'],
            ':url'         => $data['url'],
            ':ctftime_url' => $data['ctftime_url'],
            ':start_time'  => $data['start_time'],
            ':finish_time' => $data['finish_time'],
            ':format'      => $data['format'],
            ':weight'      => $data['weight'],
            ':onsite'      => (int) $data['onsite'],
            ':location'    => $data['location'],
            ':description' => $data['description'],
            ':logo_url'    => $data['logo_url'],
            ':is_safe'     => (int) $data['is_safe'],
        ]);
    }

    /**
     * Remove a single ID from parser_buffer after processing.
     */
    public function deleteFromBuffer(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM `parser_buffer` WHERE `event_id` = ?'
        );
        $stmt->execute([$id]);
    }

    // -------------------------------------------------------------------------
    // Publisher queries
    // -------------------------------------------------------------------------

    /**
     * Return all safe events not yet published to Telegram, ordered by start_time.
     * Used by the daily update mode of publisher.php.
     */
    public function getUnpublishedEvents(): array
    {
        $stmt = $this->pdo->query(
            'SELECT * FROM `ctf_events`
             WHERE `posted_at` IS NULL AND `is_safe` = 1
             ORDER BY `start_time` ASC'
        );

        return $stmt->fetchAll();
    }

    /**
     * Return safe upcoming events starting within the next $days days.
     * Used by the Monday weekly digest mode of publisher.php.
     */
    public function getUpcomingEvents(int $days): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `ctf_events`
             WHERE `is_safe` = 1
               AND `start_time` >= NOW()
               AND `start_time` <= DATE_ADD(NOW(), INTERVAL :days DAY)
             ORDER BY `start_time` ASC'
        );
        $stmt->execute([':days' => $days]);

        return $stmt->fetchAll();
    }

    /**
     * Mark an event as published by setting posted_at to the current timestamp.
     */
    public function markAsPosted(int $id): void
    {
        $stmt = $this->pdo->prepare(
            'UPDATE `ctf_events` SET `posted_at` = NOW() WHERE `id` = ?'
        );
        $stmt->execute([$id]);
    }
}
