<?php
namespace Cdash;

/**
 * Db over mysqli, for the scripts in asset/php, which run outside Omeka's
 * routing and so have no Omeka\Connection. Reads the same database.ini Omeka
 * does. In the module, a DBAL implementation replaces this class.
 */
class MysqliDb implements Db
{
    private \mysqli $conn;

    public function __construct(\mysqli $conn)
    {
        $this->conn = $conn;
    }

    public static function fromIni(string $iniPath): self
    {
        mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
        $ini = parse_ini_file($iniPath);
        if (!$ini) {
            throw new \RuntimeException('Cannot read database settings from ' . $iniPath);
        }
        $conn = new \mysqli($ini['host'], $ini['user'], $ini['password'], $ini['dbname'], (int) ($ini['port'] ?? 3306));
        $conn->set_charset('utf8mb4');
        return new self($conn);
    }

    public function query(string $sql, array $params = []): array
    {
        $result = $this->run($sql, $params);
        return $result instanceof \mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
    }

    public function scalar(string $sql, array $params = [])
    {
        $result = $this->run($sql, $params);
        if (!$result instanceof \mysqli_result) {
            return null;
        }
        $row = $result->fetch_row();
        return $row ? $row[0] : null;
    }

    public function execute(string $sql, array $params = []): int
    {
        $this->run($sql, $params);
        return max(0, (int) $this->conn->affected_rows);
    }

    public function transactional(callable $fn)
    {
        $this->conn->begin_transaction();
        try {
            $result = $fn($this);
            $this->conn->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->conn->rollback();
            throw $e;
        }
    }

    /** @return \mysqli_result|bool */
    private function run(string $sql, array $params)
    {
        if (!$params) {
            return $this->conn->query($sql);
        }
        $stmt = $this->conn->prepare($sql);
        $types = '';
        foreach ($params as $p) {
            $types .= is_int($p) ? 'i' : (is_float($p) ? 'd' : 's');
        }
        $stmt->bind_param($types, ...array_values($params));
        $stmt->execute();
        $result = $stmt->get_result();
        return $result === false ? true : $result;
    }
}
