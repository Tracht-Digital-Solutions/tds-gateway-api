<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * The customer↔owner message thread (ported from tds-customer-api's `message`).
 * This extension owns its own DB, so — like the support-tickets port —
 * customer_id / project_id carry NO foreign key: those entities live in another
 * domain. customer_id = the JWT's active company/tenant id (NULLABLE so an admin
 * broadcast / all-company view is representable). MySQL-8-safe (prod is MySQL 8).
 *
 * Class name AND numeric prefix are extension-unique (shared phinxlog rule).
 */
final class CreateMessagesMessage extends AbstractMigration
{
    public function change(): void
    {
        $this->table('messages_message', [
            'id' => true,
            'engine' => 'InnoDB',
            'collation' => 'utf8mb4_unicode_ci',
        ])
            ->addColumn('customer_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('project_id', 'integer', ['null' => true, 'signed' => false])
            ->addColumn('author_type', 'enum', ['values' => ['customer', 'owner']])
            ->addColumn('body', 'text')
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('read_at', 'datetime', ['null' => true])
            ->addColumn('edited_at', 'datetime', ['null' => true])
            ->addIndex(['customer_id', 'created_at'], ['name' => 'idx_messages_customer_created'])
            ->addIndex(['project_id', 'created_at'], ['name' => 'idx_messages_project_created'])
            ->addIndex(['read_at'], ['name' => 'idx_messages_read_at'])
            ->create();
    }
}
