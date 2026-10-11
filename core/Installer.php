<?php

namespace Core;

use PDO;
use PDOException;

class Installer
{

    public static function ensureEnvFile(string $root): void
    {
        $env = $root . '/.env';
        $example = $root . '/.env.example';

        if (!file_exists($env) && is_readable($example)) {
            @copy($example, $env);
        }
    }


    public static function install(string $root): void
    {
        if (strtolower((string) ($_ENV['AUTO_INSTALL'] ?? 'true')) === 'false') {
            return;
        }

        $lock = $root . '/.installed';
        if (file_exists($lock)) {
            return;
        }

        $schema = $root . '/database/schema.sql';
        if (!is_readable($schema)) {
            return;
        }

        $config = require $root . '/config/database.php';

        $dsn = "mysql:host={$config['host']};port={$config['port']};charset={$config['charset']}";
        $pdo = new PDO($dsn, $config['username'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        if (self::databaseReady($pdo, $config['database'])) {
            @touch($lock);
            return;
        }

        $database = str_replace('`', '', $config['database']);
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$database}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        $pdo->exec("USE `{$database}`");

        $sql = file_get_contents($schema);
        foreach (self::splitSql($sql) as $statement) {
            $pdo->exec($statement);
        }

        @touch($lock);
    }

    private static function databaseReady(PDO $pdo, string $database): bool
    {
        try {
            $pdo->exec("USE `{$database}`");
            $pdo->query('SELECT 1 FROM users LIMIT 1');
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    /**
     * Tách file .sql thành từng câu lệnh, an toàn với chuỗi và comment.
     *
     * @return string[]
     */
    private static function splitSql(string $sql): array
    {
        $statements = [];
        $buffer = '';
        $inSingle = false;
        $inBacktick = false;
        $inLineComment = false;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            $char = $sql[$i];
            $next = $i + 1 < $length ? $sql[$i + 1] : '';

            if ($inLineComment) {
                if ($char === "\n") {
                    $inLineComment = false;
                }
                continue;
            }

            if (!$inSingle && !$inBacktick && (($char === '-' && $next === '-') || $char === '#')) {
                $inLineComment = true;
                if ($char === '-') {
                    $i++;
                }
                continue;
            }

            if ($inSingle && $char === '\\') {
                $buffer .= $char . $next;
                $i++;
                continue;
            }

            if ($char === "'" && !$inBacktick) {
                $inSingle = !$inSingle;
                $buffer .= $char;
                continue;
            }

            if ($char === '`' && !$inSingle) {
                $inBacktick = !$inBacktick;
                $buffer .= $char;
                continue;
            }

            if ($char === ';' && !$inSingle && !$inBacktick) {
                $statement = trim($buffer);
                if ($statement !== '') {
                    $statements[] = $statement;
                }
                $buffer = '';
                continue;
            }

            $buffer .= $char;
        }

        $statement = trim($buffer);
        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }
}
