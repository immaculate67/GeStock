<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Database.php';

try {
    Database::connect();
    fwrite(STDOUT, "GeStock database is ready.\n");
} catch (Throwable $exception) {
    fwrite(STDERR, "Database initialisation failed. Check that the project can write to its private storage directory.\n");
    error_log('GeStock database initialisation failed: ' . $exception->getMessage());
    exit(1);
}
