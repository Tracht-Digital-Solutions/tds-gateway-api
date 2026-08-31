<?php
declare(strict_types=1);

namespace Tds\Ext\TimeTracker\Domain;

use DateTimeImmutable;
use PDO;

/**
 * Time-tracker data access, scoped per panel user (`app_user_id` = the JWT user
 * id). Supports a single running timer per user (an entry with `ended_at` NULL)
 * plus manual entries. Durations are computed in SQL so a running timer counts
 * up to NOW().
 */
final class TimeEntryRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /** The user's open (running) timer, or null. @return array<string,mixed>|null */
    public function running(int $userId): ?array
    {
        $stmt = $this->pdo->prepare(
            'SELECT id, started_at, note FROM time_entry
             WHERE app_user_id = :u AND ended_at IS NULL
             ORDER BY started_at DESC LIMIT 1'
        );
        $stmt->execute([':u' => $userId]);
        $row = $stmt->fetch();
        return $row === false ? null : $row;
    }

    /** Start a timer. Returns the existing running id if one is already open. */
    public function startTimer(int $userId, ?string $note): int
    {
        $open = $this->running($userId);
        if ($open !== null) {
            return (int) $open['id'];
        }
        $stmt = $this->pdo->prepare(
            'INSERT INTO time_entry (app_user_id, started_at, note) VALUES (:u, NOW(), :n)'
        );
        $stmt->execute([':u' => $userId, ':n' => $note]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Stop the running timer; true when one was closed. */
    public function stopTimer(int $userId): bool
    {
        $stmt = $this->pdo->prepare(
            'UPDATE time_entry SET ended_at = NOW()
             WHERE app_user_id = :u AND ended_at IS NULL'
        );
        $stmt->execute([':u' => $userId]);
        return $stmt->rowCount() > 0;
    }

    public function createManual(int $userId, string $startedAt, string $endedAt, ?string $note): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO time_entry (app_user_id, started_at, ended_at, note) VALUES (:u, :s, :e, :n)'
        );
        $stmt->execute([':u' => $userId, ':s' => $startedAt, ':e' => $endedAt, ':n' => $note]);
        return (int) $this->pdo->lastInsertId();
    }

    /** Recent entries with computed duration (minutes). @return list<array<string,mixed>> */
    public function recent(int $userId, int $limit = 50): array
    {
        $limit = max(1, min(200, $limit));
        $stmt = $this->pdo->prepare(
            "SELECT id, started_at, ended_at, note,
                    TIMESTAMPDIFF(MINUTE, started_at, COALESCE(ended_at, NOW())) AS minutes,
                    (ended_at IS NULL) AS running
             FROM time_entry WHERE app_user_id = :u
             ORDER BY started_at DESC LIMIT {$limit}"
        );
        $stmt->execute([':u' => $userId]);
        return array_map(static fn (array $r): array => [
            'id' => (int) $r['id'],
            'started_at' => (string) $r['started_at'],
            'ended_at' => $r['ended_at'] !== null ? (string) $r['ended_at'] : null,
            'note' => $r['note'] !== null ? (string) $r['note'] : null,
            'minutes' => (int) $r['minutes'],
            'running' => (int) $r['running'] === 1,
        ], $stmt->fetchAll());
    }

    public function delete(int $userId, int $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM time_entry WHERE id = :id AND app_user_id = :u');
        $stmt->execute([':id' => $id, ':u' => $userId]);
    }

    /** Total tracked minutes in the current ISO week (Mon 00:00 → now). */
    public function weekMinutes(int $userId): int
    {
        $today = new DateTimeImmutable('today');
        $monday = $today->modify('-' . ((int) $today->format('N') - 1) . ' days')->format('Y-m-d 00:00:00');
        $stmt = $this->pdo->prepare(
            'SELECT COALESCE(SUM(TIMESTAMPDIFF(MINUTE, started_at, COALESCE(ended_at, NOW()))), 0)
             FROM time_entry WHERE app_user_id = :u AND started_at >= :monday'
        );
        $stmt->execute([':u' => $userId, ':monday' => $monday]);
        return (int) $stmt->fetchColumn();
    }
}
