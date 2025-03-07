<?php

declare(strict_types=1);

namespace Doctrine\DBAL\IBMIDB2PDO\Driver;

use Doctrine\DBAL\Driver\PDO\Exception\InvalidConfiguration;
use Doctrine\DBAL\Driver\PDO\PDOConnect;
use PDO;
use PDOException;
use SensitiveParameter;

use function is_string;

class Driver extends AbstractIBMIDB2PDODriver
{
    use PDOConnect;

    /**
     * {@inheritDoc}
     */
    public function connect(
        #[SensitiveParameter]
        array $params,
    ): Connection {
        $driverOptions = $params['driverOptions'] ?? [];

        if (! empty($params['persistent'])) {
            $driverOptions[PDO::ATTR_PERSISTENT] = true;
        }

        foreach (['user', 'password'] as $key) {
            if (isset($params[$key]) && ! is_string($params[$key])) {
                throw InvalidConfiguration::notAStringOrNull($key, $params[$key]);
            }
        }

        $safeParams = $params;
        unset($safeParams['password']);

        try {
            $pdo = $this->doConnect(
                $this->constructPdoDsn($safeParams),
                $params['user'] ?? '',
                $params['password'] ?? '',
                $driverOptions,
            );
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }

        return new Connection($pdo);
    }

    /**
     * Constructs the MySQL PDO DSN.
     *
     * @param mixed[] $params
     */
    private function constructPdoDsn(array $params): string
    {
        $dsn  = 'odbc:';
        $dsn .= 'DRIVER={IBM i Access ODBC Driver};';

        if (isset($params['host']) && $params['host'] !== '') {
            $dsn .= 'SYSTEM=' . $params['host'] . ';';
        }

        if (isset($params['port'])) {
            $dsn .= 'port=' . $params['port'] . ';';
        }

        if (isset($params['dbname'])) {
            $dsn .= 'DATABASE=' . $params['dbname'] . ';';
        }

        if (isset($params['dbq'])) {
            $dsn .= 'DBQ=' . $params['dbq'] . ';';
        }

        if (isset($params['charset'])) {
            $dsn .= 'charset=' . $params['charset'] . ';';
        }

        return $dsn;
    }
}
