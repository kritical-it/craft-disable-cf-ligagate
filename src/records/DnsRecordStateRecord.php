<?php

declare(strict_types=1);

namespace KriticalIT\Ligagate\records;

use craft\db\ActiveRecord;
use KriticalIT\Ligagate\db\Table;

/**
 * @property int $id
 * @property string $hostname
 * @property string|null $recordId
 * @property string|null $recordType
 * @property bool|null $lastKnownProxied
 * @property bool|null $originalProxied
 * @property bool $disabledByPlugin
 * @property \DateTime|null $lastCheckedAt
 * @property \DateTime|null $lastChangedAt
 * @property string|null $lastError
 * @property \DateTime $dateCreated
 * @property \DateTime $dateUpdated
 * @property string $uid
 */
class DnsRecordStateRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return Table::DNS_RECORD_STATES;
    }

    public function rules(): array
    {
        return [
            [['hostname'], 'required'],
            [['hostname', 'recordId', 'recordType'], 'string'],
            [['recordId'], 'unique'],
            [['lastKnownProxied', 'originalProxied', 'disabledByPlugin'], 'boolean'],
        ];
    }
}
