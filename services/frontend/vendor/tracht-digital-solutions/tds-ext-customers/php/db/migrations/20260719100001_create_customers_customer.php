<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Customer/company directory. The class name is prefixed with the module id
 * (`Customers*`): the base API's in-process auto-migrator includes every module's
 * migrations into ONE process, so a reused class name is an uncatchable fatal
 * redeclaration. MySQL-8-safe (unsigned id).
 *
 * This is the canonical `customer` table for the panel — the master list backing
 * membership editing (tds-auth-api `app_user_customer.customer_id` references
 * these ids) and the billing/portal extensions. Distinct from tds-ext-lexware's
 * own `lx_customer` billing directory.
 */
final class CreateCustomersCustomer extends AbstractMigration
{
    public function change(): void
    {
        $this->table('customer', ['signed' => false])
            ->addColumn('name', 'string', ['limit' => 200])
            ->addColumn('email', 'string', ['limit' => 254, 'null' => true])
            ->addColumn('phone', 'string', ['limit' => 40, 'null' => true])
            ->addColumn('note', 'text', ['null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addColumn('updated_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP', 'update' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['email'], ['unique' => true, 'name' => 'uq_customer_email'])
            ->addIndex(['name'])
            ->create();
    }
}
