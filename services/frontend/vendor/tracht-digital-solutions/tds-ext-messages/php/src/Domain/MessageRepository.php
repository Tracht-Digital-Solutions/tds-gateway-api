<?php
declare(strict_types=1);

namespace Tds\Ext\Messages\Domain;

use PDO;

/**
 * Data access for the customer↔owner message thread. Ported from
 * tds-customer-api's Message actions. `customer_id` is the active company/tenant
 * id from the JWT (no FK — the customer entity lives in another domain); a NULL
 * customer_id list means "admin, all companies".
 */
final class MessageRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    private const COLS = 'id, customer_id, project_id, author_type, body, created_at, read_at, edited_at';

    /**
     * Thread for a customer (optionally a single project), oldest first.
     * $customerId === null lists every company (admin, no active company).
     *
     * @return array<int,array<string,mixed>>
     */
    public function listForCustomer(?int $customerId, ?int $projectId): array
    {
        $sql = 'SELECT ' . self::COLS . ' FROM messages_message WHERE 1=1';
        $params = [];
        if ($customerId !== null) {
            $sql .= ' AND customer_id = :cid';
            $params['cid'] = $customerId;
        }
        if ($projectId !== null) {
            $sql .= ' AND project_id = :pid';
            $params['pid'] = $projectId;
        }
        $sql .= ' ORDER BY created_at ASC, id ASC';
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(?int $customerId, ?int $projectId, string $authorType, string $body): int
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO messages_message (customer_id, project_id, author_type, body, created_at) '
            . 'VALUES (:cid, :pid, :at, :body, NOW())'
        );
        $stmt->execute([
            'cid' => $customerId,
            'pid' => $projectId,
            'at' => $authorType,
            'body' => $body,
        ]);
        return (int) $this->pdo->lastInsertId();
    }

    /**
     * Edit a message body. Admins edit any; a customer only their own
     * `author_type='customer'` rows scoped to their company. Returns whether a
     * row matched (0 → 404, un-leakable).
     */
    public function update(int $id, string $body, ?int $customerId, bool $isAdmin): bool
    {
        if ($isAdmin) {
            $stmt = $this->pdo->prepare('UPDATE messages_message SET body = :body, edited_at = NOW() WHERE id = :id');
            $stmt->execute(['body' => $body, 'id' => $id]);
        } else {
            $stmt = $this->pdo->prepare(
                "UPDATE messages_message SET body = :body, edited_at = NOW() "
                . "WHERE id = :id AND customer_id = :cid AND author_type = 'customer'"
            );
            $stmt->execute(['body' => $body, 'id' => $id, 'cid' => $customerId]);
        }
        return $stmt->rowCount() > 0;
    }

    /**
     * Count unread messages authored by the counterpart (owner→customer or
     * customer→owner). Drives the dashboard widget.
     */
    public function unreadCount(?int $customerId, bool $forOwner): int
    {
        $counterpart = $forOwner ? 'customer' : 'owner';
        $sql = "SELECT COUNT(*) FROM messages_message WHERE read_at IS NULL AND author_type = :at";
        $params = ['at' => $counterpart];
        if ($customerId !== null) {
            $sql .= ' AND customer_id = :cid';
            $params['cid'] = $customerId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return (int) $stmt->fetchColumn();
    }

    /** Mark counterpart messages in a company's thread as read for the viewer. */
    public function markRead(?int $customerId, bool $forOwner): void
    {
        $counterpart = $forOwner ? 'customer' : 'owner';
        $sql = "UPDATE messages_message SET read_at = NOW() WHERE read_at IS NULL AND author_type = :at";
        $params = ['at' => $counterpart];
        if ($customerId !== null) {
            $sql .= ' AND customer_id = :cid';
            $params['cid'] = $customerId;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
    }
}
