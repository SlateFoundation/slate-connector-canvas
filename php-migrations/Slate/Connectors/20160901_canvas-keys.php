<?php

use Emergence\Connectors\Mapping;


// skip conditions
if (!static::tableExists(Mapping::$tableName)) {
    printf("Skipping migration because table `%s` does not exist yet\n", Mapping::$tableName);
    return static::STATUS_SKIPPED;
}


// migration
$affectedRows = 0;

DB::nonQuery(
    'UPDATE `%s` SET Connector = "canvas" WHERE Connector = "CanvasIntegrator"',
    Mapping::$tableName
);
printf("Changed column `Connector` from 'CanvasIntegrator' to 'canvas' in %u rows\n", DB::affectedRows());
$affectedRows += DB::affectedRows();


// done
return $affectedRows > 0 ? static::STATUS_EXECUTED : static::STATUS_SKIPPED;