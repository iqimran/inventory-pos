<?php

namespace App\Domain\Backup;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PDO;
use RuntimeException;
use Throwable;

/**
 * Writes the application database as gzip-compressed SQL (schema + data), in PHP so no client
 * binary or credentials on a command line are needed.
 *
 * The file is written beside the target and renamed into place only when complete, so a failed
 * run never replaces a good backup with a broken one.
 */
class DatabaseDumper
{
    private const CHUNK = 500;

    public function dump(string $path): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! @mkdir($directory, 0750, true) && ! is_dir($directory)) {
            throw new RuntimeException("Cannot create the backup directory {$directory}.");
        }

        $partial = $path.'.partial';
        $file = gzopen($partial, 'wb6');

        if ($file === false) {
            throw new RuntimeException("Cannot write the backup file {$partial}.");
        }

        try {
            $this->write(DB::connection(), fn (string $sql) => gzwrite($file, $sql));
            gzclose($file);
            $file = null;

            if (! rename($partial, $path)) {
                throw new RuntimeException("Cannot move the backup into place at {$path}.");
            }
        } catch (Throwable $e) {
            if ($file) {
                gzclose($file);
            }
            @unlink($partial);

            throw $e;
        }
    }

    /**
     * @param  callable(string): mixed  $out
     */
    private function write(Connection $connection, callable $out): void
    {
        $driver = $connection->getDriverName();
        $pdo = $connection->getPdo();
        $mysql = in_array($driver, ['mysql', 'mariadb'], true);

        $out('-- '.config('app.name')." database backup\n");
        $out('-- Database: '.$connection->getDatabaseName().' ('.$driver.")\n");
        $out('-- Created: '.now()->toIso8601String()."\n\n");

        // One consistent snapshot of every InnoDB table without locking the shop out (unless already
        // inside a transaction, which would be committed implicitly by starting another).
        $snapshot = $mysql && $connection->transactionLevel() === 0;

        if ($snapshot) {
            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        }

        if ($mysql) {
            $out("SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET UNIQUE_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\n\n");
        } else {
            $out("PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n\n");
        }

        try {
            foreach ($this->tables($connection, $mysql) as $table => $create) {
                $quoted = $mysql ? '`'.str_replace('`', '``', $table).'`' : '"'.str_replace('"', '""', $table).'"';

                $out("-- Table {$table}\n");
                $out("DROP TABLE IF EXISTS {$quoted};\n{$create};\n");
                $this->rows($pdo, $quoted, $out, $mysql);
                $out("\n");
            }
        } finally {
            if ($snapshot) {
                $pdo->exec('COMMIT');
            }
        }

        $out($mysql ? "SET FOREIGN_KEY_CHECKS=1;\nSET UNIQUE_CHECKS=1;\n" : "COMMIT;\nPRAGMA foreign_keys=ON;\n");
        // Marker checked by restore procedures: a file without it is incomplete.
        $out("-- Dump completed\n");
    }

    /**
     * @return array<string, string> table => CREATE TABLE statement
     */
    private function tables(Connection $connection, bool $mysql): array
    {
        $tables = [];

        if ($mysql) {
            foreach ($connection->select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $row) {
                $name = array_values((array) $row)[0];
                $create = (array) $connection->selectOne('SHOW CREATE TABLE `'.str_replace('`', '``', $name).'`');
                $tables[$name] = $create['Create Table'];
            }

            return $tables;
        }

        foreach ($connection->select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%' ORDER BY name") as $row) {
            $tables[$row->name] = $row->sql;
        }

        return $tables;
    }

    /**
     * @param  callable(string): mixed  $out
     */
    private function rows(PDO $pdo, string $quotedTable, callable $out, bool $mysql): void
    {
        // Stream rows instead of loading a whole table into memory.
        if ($mysql) {
            $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
        }

        try {
            $this->streamRows($pdo, $quotedTable, $out);
        } finally {
            if ($mysql) {
                $pdo->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, true);
            }
        }
    }

    /**
     * @param  callable(string): mixed  $out
     */
    private function streamRows(PDO $pdo, string $quotedTable, callable $out): void
    {
        $statement = $pdo->query("SELECT * FROM {$quotedTable}");
        $statement->setFetchMode(PDO::FETCH_NUM);
        $batch = [];

        foreach ($statement as $row) {
            $batch[] = '('.implode(',', array_map(fn ($value) => $value === null ? 'NULL' : $pdo->quote((string) $value), $row)).')';

            if (count($batch) === self::CHUNK) {
                $out("INSERT INTO {$quotedTable} VALUES\n".implode(",\n", $batch).";\n");
                $batch = [];
            }
        }

        if ($batch !== []) {
            $out("INSERT INTO {$quotedTable} VALUES\n".implode(",\n", $batch).";\n");
        }

        $statement->closeCursor();
    }
}
