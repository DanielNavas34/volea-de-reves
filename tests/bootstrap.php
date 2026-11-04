<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/load_env.php';
require_once $root . '/config/database.php';

function test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}
