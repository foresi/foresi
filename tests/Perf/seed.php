<?php

/*
 * SPDX-License-Identifier: AGPL-3.0-only
 */

declare(strict_types=1);

/*
 * Seeds a migrated, empty database with the data of the performance baseline
 * (plan step 36.12) and writes what it did to var/perf/seed.json:
 *
 *     DATABASE_URL=postgresql://... php tests/Perf/seed.php --size=small|large [--password=...]
 *
 * It boots the test environment without debug, so the private services are found by
 * class and the debug middleware of DBAL does not keep every query. The owner signs
 * in as perf@example.com with perf-password-1, or with --password or PERF_PASSWORD.
 */

use Doctrine\DBAL\Connection;
use Foresi\Identity\Infrastructure\Security\SecurityUser;
use Foresi\Kernel;
use Foresi\Tests\Perf\PerfData;
use Foresi\Tests\Perf\PerfSize;
use Symfony\Component\Dotenv\Dotenv;
use Symfony\Component\PasswordHasher\Hasher\PasswordHasherFactoryInterface;

$root = dirname(__DIR__, 2);
require $root.'/vendor/autoload.php';

$options = getopt('', ['size:', 'password:']);
$sizeName = $options['size'] ?? 'small';
if (!is_string($sizeName)) {
    fwrite(STDERR, "Give --size once: small or large.\n");
    exit(1);
}
try {
    $size = PerfSize::fromName($sizeName);
} catch (InvalidArgumentException $unknown) {
    fwrite(STDERR, $unknown->getMessage()."\n");
    exit(1);
}
$password = $options['password'] ?? getenv('PERF_PASSWORD');
$password = is_string($password) && '' !== $password ? $password : PerfData::DEFAULT_PASSWORD;

$_SERVER['APP_ENV'] = $_ENV['APP_ENV'] = 'test';
$_SERVER['APP_DEBUG'] = $_ENV['APP_DEBUG'] = '0';
new Dotenv()->bootEnv($root.'/.env');
$url = getenv('DATABASE_URL');
if (!is_string($url) || str_starts_with($url, 'sqlite')) {
    fwrite(STDERR, "Set DATABASE_URL to the migrated PostgreSQL database to seed.\n");
    exit(1);
}

$kernel = new Kernel('test', false);
$kernel->boot();
$container = PerfData::testContainer($kernel);
$connection = PerfData::serviceOf($container, Connection::class);
$hashers = PerfData::serviceOf($container, PasswordHasherFactoryInterface::class);
if (false !== $connection->fetchOne('SELECT 1 FROM membership WHERE role = ?', ['owner'])) {
    fwrite(STDERR, "The database already has an owner; seed a freshly migrated one.\n");
    exit(1);
}

// Hashed the way the application hashes it, so the sign-in form accepts it.
$hash = $hashers->getPasswordHasher(SecurityUser::class)->hash($password);
$started = hrtime(true);
$world = PerfData::seed($container, $size, static function (string $type, int $done, int $total) use ($started): void {
    fwrite(STDERR, sprintf("[%7.1fs] %-18s %7d / %d  %6.1f MB\n", (hrtime(true) - $started) / 1e9, $type, $done, $total, memory_get_usage(true) / 1048576));
}, $hash);

$seconds = array_map(static fn (float $s): float => round($s, 2), $world->seconds);
$report = [
    'size' => $size->name,
    'counts' => $world->counts,
    'seconds' => $seconds,
    'rows_per_second' => array_filter(array_map(
        static fn (string $type): ?float => ($world->counts[$type] ?? 0) > 0 && ($world->seconds[$type] ?? 0.0) > 0.0 ? round($world->counts[$type] / $world->seconds[$type], 1) : null,
        array_combine(array_keys($world->counts), array_keys($world->counts)),
    ), static fn (?float $rate): bool => null !== $rate),
    'total_seconds' => round($world->totalSeconds(), 2),
    'peak_memory_mb' => round(memory_get_peak_usage(true) / 1048576, 1),
    'owner' => ['id' => $world->ownerUserId, 'email' => $world->ownerEmail],
    'samples' => [
        'contact' => $world->contactId,
        'invoice' => $world->invoiceId,
        'project' => $world->projectId,
        'task' => $world->taskId,
        'deal' => $world->dealId,
        'received_document' => $world->receivedDocumentId,
    ],
    'php' => PHP_VERSION,
    'database' => $connection->fetchOne('SELECT version()'),
];
$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
if (!is_dir($root.'/var/perf')) {
    mkdir($root.'/var/perf', 0o775, true);
}
file_put_contents($root.'/var/perf/seed.json', $json."\n");
fwrite(STDOUT, $json."\n");
$kernel->shutdown();
