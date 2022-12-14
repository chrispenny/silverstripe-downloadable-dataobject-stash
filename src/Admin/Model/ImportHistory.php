<?php

namespace ChrisPenny\DataObjectStash\Admin\Model;

use SilverStripe\ORM\DataObject;

/**
 * @property string $Filename
 */
class ImportHistory extends DataObject
{

    private static string $table_name = 'DataObjectStash_ImportHistory';

    private static array $db = [
        'Filename' => 'Varchar(255)',
    ];

    private static array $summary_fields = [
        'Filename',
        'Created',
    ];

    private static string $plural_name = 'Import History';

}
