<?php
namespace Cdash;

/**
 * The one way this library talks to the database.
 *
 * Every class in Cdash\ takes one of these and never touches mysqli itself, so
 * that moving the library into the planned CDASH module means writing a
 * DBAL-backed Db over Omeka\Connection and nothing else. SQL uses positional
 * "?" placeholders, which both mysqli and DBAL accept.
 *
 * Errors are exceptions, never return values to check.
 */
interface Db
{
    /** Rows of a SELECT, as associative arrays. */
    public function query(string $sql, array $params = []): array;

    /** The first column of the first row, or null. */
    public function scalar(string $sql, array $params = []);

    /** Run a statement; returns the number of rows it changed. */
    public function execute(string $sql, array $params = []): int;

    /**
     * Run $fn in one transaction: committed if it returns, rolled back and
     * rethrown if it throws. Returns what $fn returns.
     */
    public function transactional(callable $fn);
}
