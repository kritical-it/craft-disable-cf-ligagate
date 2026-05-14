<?php

declare(strict_types=1);

namespace KriticalIT\Ligagate\migrations;

use craft\db\Migration;
use KriticalIT\Ligagate\db\Table;

class Install extends Migration
{
    public function safeUp(): bool
    {
        $this->createTable(Table::DNS_RECORD_STATES, [
            'id' => $this->primaryKey(),
            'hostname' => $this->string(255)->notNull(),
            'recordId' => $this->string(255)->null(),
            'recordType' => $this->string(16)->null(),
            'lastKnownProxied' => $this->boolean()->null(),
            'originalProxied' => $this->boolean()->null(),
            'disabledByPlugin' => $this->boolean()->notNull()->defaultValue(false),
            'lastCheckedAt' => $this->dateTime()->null(),
            'lastChangedAt' => $this->dateTime()->null(),
            'lastError' => $this->text()->null(),
            'dateCreated' => $this->dateTime()->notNull(),
            'dateUpdated' => $this->dateTime()->notNull(),
            'uid' => $this->uid(),
        ]);

        $this->createIndex(null, Table::DNS_RECORD_STATES, ['hostname'], false);
        $this->createIndex(null, Table::DNS_RECORD_STATES, ['recordId'], true);

        return true;
    }

    public function safeDown(): bool
    {
        $this->dropTableIfExists(Table::DNS_RECORD_STATES);

        return true;
    }
}
