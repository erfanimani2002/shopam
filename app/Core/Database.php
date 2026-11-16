<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;
use RuntimeException;

/**
 * Single PDO connection. Prepared statements ONLY — no string interpolation
 * of values into SQL, ever. Values are always bound via parameters.
 */
final class Database
{
    private static ?self $instance = null;
    private PDO $pdo;

    public function __construct(array $dbConfig)
    {
        $dsn = sprintf(
            'mysql:host=%s;dbname=%s;charset=%s',
            (string) ($dbConfig['host'] ?? '127.0.0.1'),
            (string) ($dbConfig['name'] ?? ''),
            (string) ($dbConfig['charset'] ?? 'utf8mb4')
        );
        try {
            $this->pdo = new PDO($dsn, (string) ($dbConfig['user'] ?? ''), (string) ($dbConfig['pass'] ?? ''), [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements
                PDO::ATTR_STRINGIFY_FETCHES  => false,
            ]);
        } catch (\PDOException $e) {
            // Never expose credentials in the message.
            throw new RuntimeException('Database connection failed.', 0, $e);
        }
    }

    public static function get(): self
    {
        if (self::$instance === null) {
            $db = Config::get('db');
            self::$instance = new self(is_array($db) ? $db : []);
        }
        return self::$instance;
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Prepare + execute. The only way statements run in this project. */
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** @return array<int, array<string, mixed>> */
    public function all(string $sql, array $params = []): array
    {
        return $this->query($sql, $params)->fetchAll();
    }

    /** @return array<string, mixed>|null */
    public function one(string $sql, array $params = []): ?array
    {
        $row = $this->query($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->query($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    /** Affected row count for UPDATE/DELETE. */
    public function execute(string $sql, array $params = []): int
    {
        return $this->query($sql, $params)->rowCount();
    }

    /**
     * INSERT from an assoc array. Column names must be internal identifiers
     * (validated), values are bound as parameters.
     */
    public function insert(string $table, array $data): int
    {
        $cols = $this->safeColumns($table, array_keys($data));
        $list = implode(', ', $cols);
        $marks = implode(', ', array_map(static fn (string $c): string => ":$c", $cols));
        $this->query("INSERT INTO `$table` ($list) VALUES ($marks)", $data);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $cols = $this->safeColumns($table, array_keys($data));
        $set = implode(', ', array_map(static fn (string $c): string => "`$c` = :$c", $cols));
        return $this->query("UPDATE `$table` SET $set WHERE $where", array_merge($data, $whereParams))->rowCount();
    }

    /** Whitelist table/column identifiers to guard against injection via keys. */
    private function safeColumns(string $table, array $keys): array
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/', $table)) {
            throw new RuntimeException("Illegal table name: $table");
        }
        $out = [];
        foreach ($keys as $key) {
            if (!preg_match('/^[a-z][a-z0-9_]*$/', (string) $key)) {
                throw new RuntimeException("Illegal column name: $key");
            }
            $out[] = "`$key`";
        }
        return $out;
    }
}
