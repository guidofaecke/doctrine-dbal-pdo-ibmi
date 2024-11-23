<?php

declare(strict_types=1);

namespace Doctrine\DBAL\IBMIDB2PDO\Driver;

use Doctrine\DBAL\Driver\FetchUtils;
use Doctrine\DBAL\Driver\Result as ResultInterface;
use Doctrine\DBAL\Exception\InvalidColumnIndex;

use Doctrine\DBAL\IBMIDB2PDO\Driver\Exception\StatementError;
use PDO;

use PDOException;
use PDOStatement;

use function db2_fetch_array;
use function db2_fetch_assoc;
use function db2_free_result;
use function db2_num_fields;
use function db2_num_rows;
use function db2_stmt_error;

final class Result implements ResultInterface
{
    /** @internal The result can be only instantiated by its driver connection or statement. */
    public function __construct(private readonly PDOStatement $statement)
    {
    }

    public function fetchNumeric(): array|false
    {
        return $this->fetch(PDO::FETCH_NUM);
    }

    public function fetchAssociative(): array|false
    {
        return $this->fetch(PDO::FETCH_ASSOC);
    }

    public function fetchOne(): mixed
    {
        return $this->fetch(PDO::FETCH_COLUMN);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAllNumeric(): array
    {
        return $this->fetchAll(PDO::FETCH_NUM);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchAllAssociative(): array
    {
        return $this->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * {@inheritDoc}
     */
    public function fetchFirstColumn(): array
    {
        return $this->fetchAll(PDO::FETCH_COLUMN);
    }

    public function rowCount(): int
    {
        try {
            return $this->statement->rowCount();
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function columnCount(): int
    {
        try {
            return $this->statement->columnCount();
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    public function getColumnName(int $index): string
    {
        try {
            $meta = $this->statement->getColumnMeta($index);
        } catch (ValueError $exception) {
            throw InvalidColumnIndex::new($index, $exception);
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }

        if ($meta === false) {
            throw InvalidColumnIndex::new($index);
        }

        return $meta['name'];
    }

    public function free(): void
    {
        $this->statement->closeCursor();
    }

    /**
     * @psalm-param PDO::FETCH_* $mode
     *
     * @throws Exception
     */
    private function fetch(int $mode): mixed
    {
        try {
            return $this->statement->fetch($mode);
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }

    /**
     * @psalm-param PDO::FETCH_* $mode
     *
     * @return list<mixed>
     *
     * @throws Exception
     */
    private function fetchAll(int $mode): array
    {
        try {
            return $this->statement->fetchAll($mode);
        } catch (PDOException $exception) {
            throw Exception::new($exception);
        }
    }
}