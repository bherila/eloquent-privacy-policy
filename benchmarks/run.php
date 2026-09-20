<?php

/**
 * Honest benchmark: BWH\EloquentPrivacyPolicy\Benchmarks\BenchNote::privacyQuery()
 * against a hand-written, equally-correct query, at three fixture sizes.
 *
 * Usage:
 *
 *   php benchmarks/run.php
 *   DB_CONNECTION=mariadb DB_HOST=127.0.0.1 DB_PORT=3399 DB_DATABASE=testing \
 *     DB_USERNAME=root DB_PASSWORD= php benchmarks/run.php
 *
 * Same DB_* environment contract as tests/TestCase.php. See benchmarks/README.md
 * for what this measures, the results, and the plans.
 *
 * This is a plain script, not a Testbench test: the package code under test
 * (src/) touches no facade and no container (verified by inspection before
 * writing this), so Illuminate\Database\Capsule\Manager alone -- set as
 * global, with Eloquent booted -- is enough to run BenchNote::privacyQuery().
 * Testbench was not needed and is not used here.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '1');

require __DIR__.'/../vendor/autoload.php';
require __DIR__.'/Bench/Fixture.php';
require __DIR__.'/Bench/Schema.php';
require __DIR__.'/Bench/Seeder.php';
require __DIR__.'/Bench/HandwrittenQuery.php';
require __DIR__.'/Bench/Harness.php';
require __DIR__.'/Bench/BenchFolder.php';
require __DIR__.'/Bench/BenchNote.php';

use BWH\EloquentPrivacyPolicy\Benchmarks\BenchNote;
use BWH\EloquentPrivacyPolicy\Benchmarks\Fixture;
use BWH\EloquentPrivacyPolicy\Benchmarks\HandwrittenQuery;
use BWH\EloquentPrivacyPolicy\Benchmarks\Harness;
use BWH\EloquentPrivacyPolicy\Benchmarks\Schema;
use BWH\EloquentPrivacyPolicy\Benchmarks\Seeder;
use BWH\EloquentPrivacyPolicy\Context\Facts;
use BWH\EloquentPrivacyPolicy\Context\Operation;
use BWH\EloquentPrivacyPolicy\Context\PrivacyContext;
use BWH\EloquentPrivacyPolicy\Context\ResourceScope;
use BWH\EloquentPrivacyPolicy\Context\Viewer;
use BWH\EloquentPrivacyPolicy\Privacy;
use Illuminate\Database\Capsule\Manager as Capsule;
use Illuminate\Database\Connection;

const WARMUPS = 5;
const ITERATIONS = 30;
const LIMITS = [10, 100];

// ----- environment (same contract as tests/TestCase.php) --------------------

function envConnection(): string
{
    $connection = (string) (getenv('DB_CONNECTION') ?: 'sqlite');

    return match ($connection) {
        'mysql', 'mariadb', 'sqlite' => $connection,
        default => 'sqlite',
    };
}

function dbConfig(string $connection): array
{
    return match ($connection) {
        'mysql', 'mariadb' => [
            'driver' => $connection,
            'host' => getenv('DB_HOST') ?: '127.0.0.1',
            'port' => getenv('DB_PORT') ?: '3306',
            'database' => getenv('DB_DATABASE') ?: 'testing',
            'username' => getenv('DB_USERNAME') ?: 'root',
            'password' => getenv('DB_PASSWORD') !== false ? getenv('DB_PASSWORD') : '',
            'charset' => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix' => '',
            'strict' => true,
        ],
        default => [
            'driver' => 'sqlite',
            'database' => getenv('DB_DATABASE') ?: ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    };
}

/** @return array{sizes: list<int>} */
function benchSizes(): array
{
    $raw = getenv('BENCH_SIZES');

    if ($raw === false || trim($raw) === '') {
        return [1000, 10000, 100000];
    }

    return array_map(intval(...), explode(',', $raw));
}

// ----- machine / engine info --------------------------------------------------

function cpuModel(): string
{
    $uname = php_uname('s');

    if ($uname === 'Darwin') {
        $brand = trim((string) @shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null'));

        if ($brand !== '') {
            return $brand;
        }
    }

    $cpuinfo = @file_get_contents('/proc/cpuinfo');

    if (is_string($cpuinfo) && preg_match('/^model name\s*:\s*(.+)$/m', $cpuinfo, $m) === 1) {
        return trim($m[1]);
    }

    return php_uname('m');
}

function machineInfo(): array
{
    return [
        'cpu' => cpuModel(),
        'os' => php_uname('s').' '.php_uname('r'),
        'php_version' => PHP_VERSION,
    ];
}

function engineVersion(Connection $connection, string $driver): string
{
    $sql = $driver === 'sqlite' ? 'select sqlite_version() as v' : 'select version() as v';
    $row = $connection->select($sql)[0];

    return (string) (is_object($row) ? $row->v : $row['v']);
}

// ----- context ----------------------------------------------------------------

function viewerContext(): PrivacyContext
{
    return new PrivacyContext(
        Viewer::identified(Fixture::VIEWER_ID),
        new Operation('note.list'),
        new ResourceScope(['tenant_id' => Fixture::TENANT_ID]),
        facts: new Facts(['is_staff' => false]),
        now: new DateTimeImmutable(Fixture::NOW),
    );
}

// ----- one measured operation ---------------------------------------------------

/**
 * @return array{
 *   timing: array{median_ns: float, p95_ns: float, min_ns: float, max_ns: float},
 *   statement_count: int,
 *   sql: list<array{sql: string, bindings: list<mixed>, time_ms: float}>,
 *   explain: list<array<string, mixed>>,
 * }
 */
function measure(Connection $connection, string $driver, callable $run): array
{
    $captured = Harness::capture($connection, $run);
    $timing = Harness::time($run, WARMUPS, ITERATIONS);

    $explain = [];

    if ($captured['statements'] !== []) {
        $first = $captured['statements'][0];
        $explain = Harness::explain($connection, $driver, $first['sql'], $first['bindings']);
    }

    return [
        'timing' => $timing,
        'statement_count' => count($captured['statements']),
        'sql' => $captured['statements'],
        'explain' => $explain,
    ];
}

function fail(string $message): never
{
    fwrite(STDERR, "\nFATAL: $message\n");

    exit(1);
}

// ----- main ---------------------------------------------------------------------

$driver = envConnection();
$config = dbConfig($driver);

$capsule = new Capsule();
$capsule->addConnection($config);
$capsule->setAsGlobal();
$capsule->bootEloquent();

$connection = $capsule->getConnection();
$connection->disableQueryLog();

Privacy::flush();

$results = [
    'meta' => [
        ...machineInfo(),
        'driver' => $driver,
        'engine_version' => engineVersion($connection, $driver),
        'database' => $config['database'],
        'generated_at' => (new DateTimeImmutable('now'))->format(DATE_ATOM),
        'warmups' => WARMUPS,
        'iterations' => ITERATIONS,
    ],
    'sizes' => [],
];

fwrite(STDERR, sprintf(
    "driver=%s engine=%s php=%s cpu=%s\n",
    $driver,
    $results['meta']['engine_version'],
    PHP_VERSION,
    $results['meta']['cpu'],
));

foreach (benchSizes() as $totalNotes) {
    fwrite(STDERR, "\n== size=$totalNotes ==\n");

    Schema::create($connection);

    $seedStart = hrtime(true);
    $seedMeta = Seeder::seed($connection, $totalNotes);
    $seedMs = (hrtime(true) - $seedStart) / 1_000_000;

    fwrite(STDERR, sprintf("seeded in %.1f ms (tenant slice=%d, expected visible=%d)\n", $seedMs, $seedMeta['tenant_slice'], $seedMeta['expected_visible']));

    $context = viewerContext();

    // ----- correctness gate: package and handwritten must agree, and both must
    // match the fixture's own analytically-expected visible count, before any
    // timing happens.

    $packageIds = BenchNote::privacyQuery($context)->orderBy('id')->get()->modelKeys();
    $handwrittenIds = array_map(
        static fn (object $row): int => (int) $row->id,
        HandwrittenQuery::build($connection, Fixture::TENANT_ID, Fixture::VIEWER_ID, Fixture::NOW)->orderBy('id')->get()->all(),
    );

    if ($packageIds !== $handwrittenIds) {
        $onlyPackage = array_diff($packageIds, $handwrittenIds);
        $onlyHandwritten = array_diff($handwrittenIds, $packageIds);

        fail(sprintf(
            "size=%d: package and handwritten disagree. package=%d rows, handwritten=%d rows. only-in-package=[%s] only-in-handwritten=[%s]",
            $totalNotes,
            count($packageIds),
            count($handwrittenIds),
            implode(',', array_slice($onlyPackage, 0, 20)),
            implode(',', array_slice($onlyHandwritten, 0, 20)),
        ));
    }

    $packageCount = BenchNote::privacyQuery($context)->count();
    $handwrittenCount = HandwrittenQuery::build($connection, Fixture::TENANT_ID, Fixture::VIEWER_ID, Fixture::NOW)->count();

    if ($packageCount !== count($packageIds) || $handwrittenCount !== count($handwrittenIds) || $packageCount !== $handwrittenCount) {
        fail(sprintf(
            'size=%d: count() disagrees with get(): package get=%d package count=%d handwritten get=%d handwritten count=%d',
            $totalNotes,
            count($packageIds),
            $packageCount,
            count($handwrittenIds),
            $handwrittenCount,
        ));
    }

    if ($packageCount !== $seedMeta['expected_visible']) {
        fail(sprintf(
            'size=%d: measured visible count (%d) does not match the fixture\'s own analytically-expected visible count (%d) -- the seeder or the bucket math has a bug.',
            $totalNotes,
            $packageCount,
            $seedMeta['expected_visible'],
        ));
    }

    $visiblePercentage = $totalNotes > 0 ? (100.0 * $packageCount / $totalNotes) : 0.0;

    fwrite(STDERR, sprintf("parity OK: %d/%d visible (%.2f%%)\n", $packageCount, $totalNotes, $visiblePercentage));

    $operations = [];

    foreach (LIMITS as $limit) {
        fwrite(STDERR, "  get(limit=$limit) ... ");

        $operations["get_limit_$limit"] = [
            'package' => measure($connection, $driver, static fn () => BenchNote::privacyQuery($context)->orderBy('id')->limit($limit)->get()),
            'handwritten' => measure($connection, $driver, static fn () => HandwrittenQuery::build($connection, Fixture::TENANT_ID, Fixture::VIEWER_ID, Fixture::NOW)->orderBy('id')->limit($limit)->get()),
        ];

        fwrite(STDERR, "done\n");
    }

    fwrite(STDERR, "  count() ... ");

    $operations['count'] = [
        'package' => measure($connection, $driver, static fn () => BenchNote::privacyQuery($context)->count()),
        'handwritten' => measure($connection, $driver, static fn () => HandwrittenQuery::build($connection, Fixture::TENANT_ID, Fixture::VIEWER_ID, Fixture::NOW)->count()),
    ];

    fwrite(STDERR, "done\n");

    $packageStatementsAt10 = $operations['get_limit_10']['package']['statement_count'];
    $packageStatementsAt100 = $operations['get_limit_100']['package']['statement_count'];

    if ($packageStatementsAt10 !== $packageStatementsAt100) {
        fail(sprintf(
            'size=%d: package statement count differs between N=10 (%d) and N=100 (%d); the query count should depend on policy shape, not page size.',
            $totalNotes,
            $packageStatementsAt10,
            $packageStatementsAt100,
        ));
    }

    $results['sizes'][(string) $totalNotes] = [
        'seed' => [
            'seed_time_ms' => $seedMs,
            ...$seedMeta,
        ],
        'parity' => [
            'visible_count' => $packageCount,
            'visible_percentage' => $visiblePercentage,
            'ids_match' => true,
            'count_match' => true,
        ],
        'operations' => $operations,
    ];
}

Schema::drop($connection);

$resultsDir = __DIR__.'/results';

if (! is_dir($resultsDir)) {
    mkdir($resultsDir, 0777, true);
}

$outFile = $resultsDir.'/'.$driver.'.json';
file_put_contents($outFile, json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

fwrite(STDERR, "\nwrote $outFile\n");

// ----- human-readable summary to stdout -----------------------------------------

printf("driver=%s engine=%s php=%s os=%s cpu=%s\n", $driver, $results['meta']['engine_version'], PHP_VERSION, $results['meta']['os'], $results['meta']['cpu']);

foreach ($results['sizes'] as $size => $data) {
    printf("\n-- size=%s (visible %d = %.2f%%) --\n", $size, $data['parity']['visible_count'], $data['parity']['visible_percentage']);
    printf("%-14s %10s %10s %10s %10s %10s %10s\n", 'operation', 'pkg p50ms', 'pkg p95ms', 'hw p50ms', 'hw p95ms', 'pkg stmts', 'hw stmts');

    foreach ($data['operations'] as $name => $op) {
        printf(
            "%-14s %10.3f %10.3f %10.3f %10.3f %10d %10d\n",
            $name,
            $op['package']['timing']['median_ns'] / 1_000_000,
            $op['package']['timing']['p95_ns'] / 1_000_000,
            $op['handwritten']['timing']['median_ns'] / 1_000_000,
            $op['handwritten']['timing']['p95_ns'] / 1_000_000,
            $op['package']['statement_count'],
            $op['handwritten']['statement_count'],
        );
    }
}
