<?php

declare(strict_types=1);

namespace AniTools;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use InvalidArgumentException;

final class DBService
{
    /** @var AbstractSchemaManager<PostgreSQLPlatform> */
    public static ?AbstractSchemaManager $schemaManager = null;
    /** @var array<string, list<string>> */
    public static array $schemaCache = [];

    public static function getDBConnection(): Connection
    {
        $connectionParams = [
            'dbname' => getenv('DB_DATABASE'),
            'user' => getenv('DB_USER'),
            'password' => getenv('DB_PASSWORD'),
            'host' => 'postgres',
            'driver' => 'pdo_pgsql',
        ];
        $conn = DriverManager::getConnection($connectionParams);

        // Register ENUM types with DBAL so it doesn't throw errors when using the SchemaManager in the Importer
        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('media_media_type', 'string');
        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('media_season', 'string');
        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('media_format', 'string');
        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('media_source', 'string');
        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('media_status', 'string');
        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('media_characters_role', 'string');
        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('media_external_ids_service', 'string');
        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('media_external_ids_source', 'string');
        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('media_relations_relation_type', 'string');
        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('user_list_activities_status', 'string');
        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('user_lists_media_type', 'string');
        $conn->getDatabasePlatform()->registerDoctrineTypeMapping('user_media_status', 'string');

        return $conn;
    }

    /**
     * Doctrine DBAL doesn't support batch inserts yet so we hardcode our unprepared statements like bad boys
     * @param list<array<string, mixed>> $values
     */
    public static function getBatchInsertFor(
        string $table,
        array $values,
        ?string $constraint = null,
        bool $update = false
    ): string {
        if (\count($values) === 0) {
            throw new InvalidArgumentException('No values were passed');
        }

        // Get column names from the first row of values
        $cols = array_keys($values[0]);
        $ins = 'INSERT INTO "' . $table . '" ("' . implode('","', $cols) . '") VALUES ';
        $vals = [];
        foreach ($values as $row) {
            $row = array_map(function ($v) {
                if (is_array($v)) {
                    return "'" . str_replace("'", "''", json_encode($v, JSON_UNESCAPED_UNICODE)) . "'";
                }
                if (is_string($v)) {
                    // Escape any single quotes while quoting the value
                    return "'" . str_replace("\\", "", str_replace("'", "''", $v)) . "'";
                }
                if (is_bool($v)) {
                    return $v ? 'true' : 'false';
                }
                if ($v === null) {
                    return 'null';
                }
                return $v;
            }, $row);

            $vals[] = '(' . implode(',', $row) . ')';
        }

        $ins .= implode(',', $vals);

        if ($constraint === null) {
            return $ins;
        }
        $ins .= ' ON CONFLICT ON CONSTRAINT ' . $constraint;

        if ($update === false) {
            $ins .= ' DO NOTHING';
        } else {
            if (! array_key_exists($table, self::$schemaCache)) {
                if (self::$schemaManager === null) {
                    self::$schemaManager = self::getDBConnection()->createSchemaManager();
                }
                $tableSchema = self::$schemaManager->introspectTable('media');
                $pkCols = $tableSchema->getPrimaryKey()->getColumns();
                self::$schemaCache[$table] = $pkCols;
            }

            $updCols = $cols;
            foreach (self::$schemaCache[$table] as $pkCol) {
                if (($key = array_search($pkCol, $updCols, true)) !== false) {
                    unset($updCols[$key]);
                }
            }

            $ins .= ' DO UPDATE SET ';
            $upd = [];
            foreach ($updCols as $updCol) {
                $upd[] = $updCol . ' = EXCLUDED.' . $updCol;
            }
            $ins .= implode(', ', $upd);
        }

        return $ins;
    }
}
