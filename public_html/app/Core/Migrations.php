<?php
declare(strict_types=1);
namespace Sfera;

function migrateDatabase(\PDO $pdo, string $directory): array
{
    $lock =
        "sfera:" .
        substr(
            hash(
                "sha256",
                (string) $pdo->query("SELECT DATABASE()")->fetchColumn(),
            ),
            0,
            40,
        );
    $stmt = $pdo->prepare("SELECT GET_LOCK(?,5)");
    $stmt->execute([$lock]);
    if (!(int) $stmt->fetchColumn()) {
        throw new \RuntimeException("Migration busy");
    }
    try {
        $pdo->exec(
            "CREATE TABLE IF NOT EXISTS schema_migrations(version VARCHAR(100) PRIMARY KEY, checksum CHAR(64) NOT NULL, applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB",
        );
        $applied = [];
        $files = glob($directory . "/*.sql");
        sort($files);
        foreach ($files as $file) {
            $name = basename($file);
            $hash = hash_file("sha256", $file);
            $check = $pdo->prepare(
                "SELECT checksum FROM schema_migrations WHERE version=?",
            );
            $check->execute([$name]);
            $old = $check->fetchColumn();
            if ($old) {
                if (!hash_equals($old, $hash)) {
                    throw new \RuntimeException("Migration checksum mismatch");
                }
                continue;
            }
            // Each migration is a SQL script. Read all results, including errors
            // from later statements and from the conditional SQL in 011/012.
            $emulated = (bool) $pdo->getAttribute(\PDO::ATTR_EMULATE_PREPARES);
            $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, true);
            try {
                $sql = file_get_contents($file);
                if ($sql === false) {
                    throw new \RuntimeException("Cannot read migration: " . $name);
                }
                $result = $pdo->query($sql);
                while ($result->nextRowset()) {
                    // Advancing discards the previous result set.
                }
                $result->closeCursor();
            } finally {
                $pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, $emulated);
            }
            $insert = $pdo->prepare(
                "INSERT INTO schema_migrations(version,checksum) VALUES(?,?)",
            );
            $insert->execute([$name, $hash]);
            $applied[] = $name;
        }
        return $applied;
    } finally {
        $stmt = $pdo->prepare("SELECT RELEASE_LOCK(?)");
        $stmt->execute([$lock]);
    }
}
