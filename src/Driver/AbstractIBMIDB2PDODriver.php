<?php

declare(strict_types=1);

namespace Doctrine\DBAL\IBMIDB2PDO\Driver;

use Doctrine\DBAL\Driver;
use Doctrine\DBAL\Driver\API\ExceptionConverter as ExceptionConverterInterface;
use Doctrine\DBAL\Driver\API\IBMDB2\ExceptionConverter;
use Doctrine\DBAL\IBMIDB2PDO\Platforms\IBMIDB2PDOPlatform;
use Doctrine\DBAL\Platforms\AbstractPlatform;
use Doctrine\DBAL\ServerVersionProvider;

abstract class AbstractIBMIDB2PDODriver implements Driver
{
    #[\Override]
    public function getDatabasePlatform(ServerVersionProvider $versionProvider): AbstractPlatform
    {
        return new IBMIDB2PDOPlatform();
    }

    #[\Override]
    public function getExceptionConverter(): ExceptionConverterInterface
    {
        return new ExceptionConverter();
    }
}
