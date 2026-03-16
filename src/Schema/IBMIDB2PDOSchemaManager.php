<?php

declare(strict_types=1);

namespace Doctrine\DBAL\IBMIDB2PDO\Schema;

use Doctrine\DBAL\IBMIDB2PDO\Platforms\IBMIDB2PDOPlatform;
use Doctrine\DBAL\Result;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Doctrine\DBAL\Schema\Column;
use Doctrine\DBAL\Schema\ForeignKeyConstraint;
use Doctrine\DBAL\Schema\Identifier;
use Doctrine\DBAL\Schema\View;
use Doctrine\DBAL\Types\Type;
use Doctrine\DBAL\Types\Types;

use function array_change_key_case;
use function implode;
use function preg_match;
use function str_replace;
use function strpos;
use function strtolower;
use function strtoupper;
use function substr;

use const CASE_LOWER;

/**
 * IBM Db2 PDO Schema Manager.
 *
 * @extends AbstractSchemaManager<IBMIDB2PDOPlatform>
 */
final class IBMIDB2PDOSchemaManager extends AbstractSchemaManager
{
    /**
     * {@inheritDoc}
     */
    #[\Override]
    protected function _getPortableTableColumnDefinition($tableColumn): Column
    {
        $tableColumn = array_change_key_case($tableColumn, CASE_LOWER);

        $length = $precision = $default = null;
        $scale  = 0;
        $fixed  = false;

        if ($tableColumn['default'] !== null && $tableColumn['default'] !== 'NULL') {
            $default = $tableColumn['default'];

            if (preg_match('/^\'(.*)\'$/s', (string) $default, $matches) === 1) {
                $default = str_replace("''", "'", $matches[1]);
            }
        }

        $type = $this->platform->getDoctrineTypeMapping($tableColumn['typename']);

        switch (strtolower((string) $tableColumn['typename'])) {
            case 'character varying':
            case 'datalink':
            case 'national character varying':
            case 'varchar':
                if ($tableColumn['codepage'] === 0) {
                    $type = Types::BINARY;
                }

                $length = $tableColumn['length'];
                break;

            case 'binary':
                $type   = Types::BINARY;
                $length = $tableColumn['length'];
                break;

            case 'character':
            case 'graphic':
            case 'national character':
                if ($tableColumn['codepage'] === 0) {
                    $type = Types::BINARY;
                }

                $length = $tableColumn['length'];
                $fixed  = true;
                break;

            case 'character large object':
            case 'clob':
            case 'national character large object':
                $length = $tableColumn['length'];
                break;

            case 'decimal':
            case 'double':
            case 'double precision':
            case 'numeric':
            case 'real':
                $scale     = (int) $tableColumn['scale'];
                $precision = (int) $tableColumn['length'];
                break;
        }

        $options = [
            'length'          => $length === null ? null : (int) $length,
            'unsigned'        => false,
            'fixed'           => $fixed,
            'default'         => $default,
            'autoincrement'   => (bool) $tableColumn['autoincrement'],
            'notnull'         => $tableColumn['nulls'] === '0',
            'platformOptions' => [],
        ];

        if (isset($tableColumn['comment'])) {
            $options['comment'] = $tableColumn['comment'];
        }

        if ($precision !== null) {
            $options['scale']     = $scale;
            $options['precision'] = $precision;
        }

        return new Column($tableColumn['column_name'], Type::getType($type), $options);
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    protected function _getPortableTableDefinition(array $table): string
    {
        $table = array_change_key_case($table, CASE_LOWER);

        return $table['name'];
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    protected function _getPortableTableIndexesList(array $rows, string $tableName): array
    {
        foreach ($rows as &$tableIndexRow) {
            $tableIndexRow            = array_change_key_case($tableIndexRow);
            $tableIndexRow['primary'] = (bool) $tableIndexRow['primary'];
        }

        return parent::_getPortableTableIndexesList($rows, $tableName);
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    protected function _getPortableTableForeignKeyDefinition(array $tableForeignKey): ForeignKeyConstraint
    {
        return new ForeignKeyConstraint(
            $tableForeignKey['local_columns'],
            $tableForeignKey['foreign_table'],
            $tableForeignKey['foreign_columns'],
            $tableForeignKey['name'],
            $tableForeignKey['options'],
        );
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    protected function _getPortableTableForeignKeysList(array $rows): array
    {
        $foreignKeys = [];

        foreach ($rows as $tableForeignKey) {
            $tableForeignKey = array_change_key_case($tableForeignKey);

            if (! isset($foreignKeys[$tableForeignKey['index_name']])) {
                $foreignKeys[$tableForeignKey['index_name']] = [
                    'local_columns'   => [$tableForeignKey['local_column']],
                    'foreign_table'   => $tableForeignKey['foreign_table'],
                    'foreign_columns' => [$tableForeignKey['foreign_column']],
                    'name'            => $tableForeignKey['index_name'],
                    'options'         => [
                        'onUpdate' => $tableForeignKey['on_update'],
                        'onDelete' => $tableForeignKey['on_delete'],
                    ],
                ];
            } else {
                $foreignKeys[$tableForeignKey['index_name']]['local_columns'][]   = $tableForeignKey['local_column'];
                $foreignKeys[$tableForeignKey['index_name']]['foreign_columns'][] = $tableForeignKey['foreign_column'];
            }
        }

        return parent::_getPortableTableForeignKeysList($foreignKeys);
    }

    /**
     * {@inheritDoc}
     */
    #[\Override]
    protected function _getPortableViewDefinition(array $view): View
    {
        $view = array_change_key_case($view, CASE_LOWER);

        $sql = '';
        $pos = strpos((string) $view['text'], ' AS ');

        if ($pos !== false) {
            $sql = substr((string) $view['text'], $pos + 4);
        }

        return new View($view['name'], $sql);
    }

    #[\Override]
    protected function normalizeName(string $name): string
    {
        $identifier = new Identifier($name);

        return $identifier->isQuoted() ? $identifier->getName() : strtoupper($name);
    }

    #[\Override]
    protected function selectTableNames(string $databaseName): Result
    {
        $sql = <<<'SQL'
SELECT NAME
FROM QSYS2.SYSTABLES
WHERE TYPE IN ('T', 'P')
SQL;

        return $this->connection->executeQuery($sql, [$databaseName]);
    }

    #[\Override]
    protected function selectTableColumns(string $databaseName, ?string $tableName = null): Result
    {
        $sql = 'SELECT';

        if ($tableName === null) {
            $sql .= ' C.TABLE_NAME AS NAME,';
        }

        $sql .= <<<'SQL_WRAP'
       C.COLUMN_NAME,
       C.DATA_TYPE AS TYPENAME,
       C.CHARACTER_SET_NAME AS CODEPAGE,
--        D.NULLABLE AS NULLS,
       D.NULLABLE AS nulls,
       D.COLUMN_SIZE AS LENGTH,
       C.NUMERIC_SCALE AS SCALE,
       D.COLUMN_TEXT AS COMMENT,
       CASE
           WHEN C.IDENTITY_GENERATION IS NOT NULL THEN 1
           ELSE 0
           END   AS AUTOINCREMENT,
       C.COLUMN_DEFAULT as DEFAULT
    FROM SYSIBM.COLUMNS C
         JOIN QSYS2.TABLES AS T
              ON T.TABLE_SCHEMA = C.TABLE_SCHEMA
                  AND T.TABLE_NAME = C.TABLE_NAME
         JOIN SYSIBM.SQLCOLUMNS AS D
              ON D.TABLE_SCHEM = C.TABLE_SCHEMA
                  AND D.TABLE_NAME = C.TABLE_NAME
                  AND D.COLUMN_NAME = C.COLUMN_NAME
SQL_WRAP;

        $conditions = ["T.TABLE_TYPE = 'BASE TABLE'"];
        $params     = [];

        if ($tableName !== null) {
            $conditions[] = 'C.TABLE_NAME = ?';
            $params[]     = $tableName;
        }

        $sql .= ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY C.TABLE_NAME, C.ORDINAL_POSITION';

        return $this->connection->executeQuery($sql, $params);
    }

    #[\Override]
    protected function selectIndexColumns(string $databaseName, ?string $tableName = null): Result
    {
        $sql1 = 'SELECT';

        if ($tableName === null) {
            $sql1 .= ' T.TABLE_NAME AS NAME,';
        }

        $sql1 .= <<<'SQL'
             CSTCOL.CONSTRAINT_NAME AS KEY_NAME,
             CSTCOL.COLUMN_NAME AS COLUMN_NAME,
             CASE 
                 WHEN CST.CONSTRAINT_TYPE = 'PRIMARY KEY' 
                     THEN 1 
                     ELSE 0 
                 END AS PRIMARY,
             CASE 
                 WHEN (CST.CONSTRAINT_TYPE = 'UNIQUE' OR CST.CONSTRAINT_TYPE = 'PRIMARY KEY') 
                     THEN 0 
                     ELSE 1 
                 END AS NON_UNIQUE,
             ORDINAL_POSITION AS COLPOS
        FROM QSYS2.SYSCST AS CST
        JOIN QSYS2.SYSTABLES AS T
          ON CST.TABLE_SCHEMA = T.TABLE_SCHEMA AND CST.TABLE_NAME = T.TABLE_NAME
        JOIN QSYS2.SYSCSTCOL AS CSTCOL
          ON CST.CONSTRAINT_SCHEMA = CSTCOL.CONSTRAINT_SCHEMA AND CST.CONSTRAINT_NAME = CSTCOL.CONSTRAINT_NAME
        JOIN SYSIBM.COLUMNS AS C
          ON C.TABLE_NAME = T.TABLE_NAME AND C.COLUMN_NAME = CSTCOL.COLUMN_NAME
SQL;

        $conditions = ['T.TABLE_SCHEMA = ?', "T.TABLE_TYPE = 'T'"];
        $params     = [$databaseName];

        if ($tableName !== null) {
            $conditions[] = 'T.TABLE_NAME = ?';
            $params[]     = $tableName;
        }

        $sql1 .= ' WHERE ' . implode(' AND ', $conditions);

        $sql2 = 'SELECT';

        if ($tableName === null) {
            $sql2 .= ' IDX.TABLE_NAME AS NAME,';
        }

        $sql2 .= <<<'SQL'
             IDX.INDEX_NAME AS KEY_NAME,
             IDXCOL.COLUMN_NAME AS COLUMN_NAME,
             CASE
                 WHEN IDX.IS_UNIQUE = 'P' THEN 1
                 ELSE 0
             END AS PRIMARY,
             CASE
                 WHEN IDX.IS_UNIQUE = 'D' THEN 1
                 ELSE 0
             END AS NON_UNIQUE,
             COLUMN_POSITION AS COLPOS
        FROM QSYS2.SYSindexes AS IDX
        JOIN QSYS2.SYSTABLES AS T
          ON IDX.TABLE_SCHEMA = T.TABLE_SCHEMA AND IDX.TABLE_NAME = T.TABLE_NAME
        JOIN QSYS2.SYSKEYS AS IDXCOL
          ON IDX.INDEX_SCHEMA = IDXCOL.INDEX_SCHEMA AND IDX.INDEX_NAME = IDXCOL.INDEX_NAME
SQL;

        $conditions = ['IDX.TABLE_SCHEMA = ?', "T.TABLE_TYPE = 'T'"];
        $params[]   = $databaseName;

        if ($tableName !== null) {
            $conditions[] = 'IDX.TABLE_NAME = ?';
            $params[]     = $tableName;
        }

        $sql2 .= ' WHERE ' . implode(' AND ', $conditions);

        $sql = 'SELECT';

        if ($tableName === null) {
            $sql .= '    NAME,';
        }

        $sql .= <<<'SQL'
    KEY_NAME,
    COLUMN_NAME,
    PRIMARY,
    NON_UNIQUE
FROM (
SQL;
        $sql .= $sql1;
        $sql .= ' UNION ALL ';
        $sql .= $sql2;
        $sql .= ') AS all_data ORDER BY KEY_NAME, COLPOS';

        return $this->connection->executeQuery($sql, $params);
    }

    #[\Override]
    protected function selectForeignKeyColumns(string $databaseName, ?string $tableName = null): Result
    {
        $sql = 'SELECT';

        if ($tableName === null) {
            $sql .= ' R.TABLE_NAME AS NAME,';
        }

        $sql .= <<<'SQL'
             FKCOL.COLNAME AS LOCAL_COLUMN,
             R.TABLE_NAME AS FOREIGN_TABLE,
             PKCOL.COLNAME AS FOREIGN_COLUMN,
             R.CONSTRAINT_NAME AS INDEX_NAME,
             CASE
                 WHEN C.UPDATE_RULE = 'R' THEN 'RESTRICT'
             END AS ON_UPDATE,
             CASE
                 WHEN C.DELETE_RULE = 'C' THEN 'CASCADE'
                 WHEN C.DELETE_RULE = 'N' THEN 'SET NULL'
                 WHEN C.DELETE_RULE = 'R' THEN 'RESTRICT'
             END AS ON_DELETE
        FROM QSYS2.SYSCST AS R
         JOIN QSYS2.SYSREFCST AS S
              ON R.CONSTRAINT_SCHEMA = S.CONSTRAINT_SCHEMA
                  AND R.CONSTRAINT_NAME = S.CONSTRAINT_NAME
         JOIN QSYS2.REF_CONSTRAINTS AS C
              ON C.CONSTRAINT_SCHEMA = R.CONSTRAINT_SCHEMA
                  AND C.CONSTRAINT_NAME = R.CONSTRAINT_NAME
         JOIN QSYS2.TABLES AS T
              ON T.TABLE_SCHEMA = R.TABLE_SCHEMA
                  AND T.TABLE_NAME = R.TABLE_NAME
         JOIN QSYS2.SYSKEYCST AS FKCOL
              ON FKCOL.CONSTRAINT_NAME = R.CONSTRAINT_NAME
                  AND FKCOL.TABLE_SCHEMA = R.TABLE_SCHEMA
                  AND FKCOL.TABLE_NAME = R.TABLE_NAME
         JOIN QSYS2.SYSKEYCST AS PKCOL
              ON PKCOL.CONSTRAINT_NAME = S.UNIQUE_CONSTRAINT_NAME
                  AND PKCOL.TABLE_SCHEMA = S.UNIQUE_CONSTRAINT_SCHEMA
                  AND PKCOL.TABLE_NAME =S.UNIQUE_CONSTRAINT_NAME
                  AND PKCOL.COLUMN_POSITION = FKCOL.COLUMN_POSITION
SQL;

        $conditions = ['R.TABLE_SCHEMA = ?', "T.TABLE_TYPE = 'T'"];
        $params     = [$databaseName];

        if ($tableName !== null) {
            $conditions[] = 'R.TABLE_NAME = ?';
            $params[]     = $tableName;
        }

        $sql .= ' WHERE ' . implode(' AND ', $conditions) . ' ORDER BY R.CONSTRAINT_NAME, FKCOL.COLSEQ';

        return $this->connection->executeQuery($sql, $params);
    }

    /**
     * {@inheritDoc}
     *
     * @return array<non-empty-string, array{comment: mixed}>
     */
    #[\Override]
    protected function fetchTableOptionsByTable(string $databaseName, ?string $tableName = null): array
    {
        $sql = 'SELECT NAME, REMARKS';

        $conditions = [];
        $params     = [];

        if ($tableName !== null) {
            $conditions[] = 'NAME = ?';
            $params[]     = $tableName;
        }

        $sql .= ' FROM QSYS2.SYSTABLES';

        if ($conditions !== []) {
            $sql .= ' WHERE ' . implode(' AND ', $conditions);
        }

        /** @var array<non-empty-string, array<string, mixed>> $metadata */
        $metadata = $this->connection->executeQuery($sql, $params)
            ->fetchAllAssociativeIndexed();

        $tableOptions = [];
        foreach ($metadata as $table => $data) {
            $data = array_change_key_case($data, CASE_LOWER);

            $tableOptions[$table] = ['comment' => $data['remarks']];
        }

        return $tableOptions;
    }
}
