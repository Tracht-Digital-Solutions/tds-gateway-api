<?php
declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Time-tracker schema. NB the class name is prefixed with the module id
 * (`TimeTracker*`): the base API's in-process auto-migrator includes every
 * module's migrations into ONE PHP process, so a reused class name is an
 * uncatchable fatal redeclaration. Every extension migration MUST prefix.
 */
final class CreateTimeTrackerEntry extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('time_entry', ['signed' => false]);
        $table
            ->addColumn('app_user_id', 'integer', ['signed' => false])
            ->addColumn('started_at', 'datetime')
            ->addColumn('ended_at', 'datetime', ['null' => true])
            ->addColumn('note', 'string', ['limit' => 500, 'null' => true])
            ->addColumn('created_at', 'datetime', ['default' => 'CURRENT_TIMESTAMP'])
            ->addIndex(['app_user_id', 'started_at'])
            ->create();
    }
}
