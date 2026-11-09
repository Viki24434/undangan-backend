<?php

declare(strict_types=1);

namespace App\Core;

use PDO;
use PDOStatement;

final class Database
{
    public readonly PDO $pdo;

    public function __construct(array $config, bool $selectDatabase = true)
    {
        if (!preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $config['name'])) {
            throw new \RuntimeException('DB_NAME tidak valid.');
        }
        $dsn = "mysql:host={$config['host']};port={$config['port']};charset=utf8mb4";
        if ($selectDatabase) {
            $dsn .= ';dbname=' . $config['name'];
        }
        $this->pdo = new PDO($dsn, $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_STRINGIFY_FETCHES => false,
            PDO::ATTR_TIMEOUT => 10,
        ]);
        $this->pdo->exec("SET time_zone = '+00:00'");
    }

    public function query(string $sql, array $parameters = []): PDOStatement
    {
        $statement = $this->pdo->prepare($sql);
        foreach (array_values($parameters) as $index => $value) {
            $type = match (true) {
                is_int($value) => PDO::PARAM_INT,
                is_bool($value) => PDO::PARAM_BOOL,
                $value === null => PDO::PARAM_NULL,
                default => PDO::PARAM_STR,
            };
            $statement->bindValue($index + 1, $value, $type);
        }
        $statement->execute();
        return $statement;
    }

    public function one(string $sql, array $parameters = []): ?array
    {
        return $this->query($sql, $parameters)->fetch() ?: null;
    }

    public function all(string $sql, array $parameters = []): array
    {
        return $this->query($sql, $parameters)->fetchAll();
    }

    public function run(string $sql, array $parameters = []): int
    {
        return $this->query($sql, $parameters)->rowCount();
    }

    public function transaction(callable $work): mixed
    {
        $this->pdo->beginTransaction();
        try {
            $result = $work($this);
            $this->pdo->commit();
            return $result;
        } catch (\Throwable $error) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $error;
        }
    }
}
