<?php
declare(strict_types=1);
require_once __DIR__.'/lib/ResearchDb.php';require_once __DIR__.'/lib/FirmwareImporter.php';
if(PHP_SAPI!=='cli'||$argc<2){fwrite(STDERR,"Usage: php cli_import.php firmware.zip [notes]\n");exit(2);} $private=getenv('PRIVATE_DIR')?:dirname(__DIR__,2).'/private';$db=ResearchDb::open($private);$id=(new FirmwareImporter($db,$private))->import($argv[1],basename($argv[1]),$argv[2]??'CLI import');echo "Imported package #$id\n";
