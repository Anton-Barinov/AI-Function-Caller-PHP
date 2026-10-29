<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/autoload.php';
require_once __DIR__ . '/SchemaGeneratorTest.php';
require_once __DIR__ . '/HydratorTest.php';
require_once __DIR__ . '/ProviderParityTest.php';
require_once __DIR__ . '/FunctionCallerTest.php';
require_once __DIR__ . '/ChaosEdgeCasesTest.php';

use AiFunctionCaller\Tests\ChaosEdgeCasesTest;
use AiFunctionCaller\Tests\FunctionCallerTest;
use AiFunctionCaller\Tests\HydratorTest;
use AiFunctionCaller\Tests\ProviderParityTest;
use AiFunctionCaller\Tests\SchemaGeneratorTest;

echo "\033[1;36m====================================================\033[0m\n";
echo "\033[1;36m       AI-Function-Caller-PHP Test Suite            \033[0m\n";
echo "\033[1;36m====================================================\033[0m\n\n";

$suites = [
    'SchemaGeneratorTest' => new SchemaGeneratorTest(),
    'HydratorTest' => new HydratorTest(),
    'ProviderParityTest' => new ProviderParityTest(),
    'FunctionCallerTest' => new FunctionCallerTest(),
    'ChaosEdgeCasesTest' => new ChaosEdgeCasesTest(),
];

$passed = 0;
$failed = 0;
$startTime = microtime(true);

foreach ($suites as $name => $suite) {
    echo "Running \033[1;33m{$name}\033[0m... ";
    try {
        $suite->run();
        echo "\033[1;32mPASSED\033[0m\n";
        $passed++;
    } catch (\Throwable $e) {
        echo "\033[1;31mFAILED\033[0m\n";
        echo "  Error: " . $e->getMessage() . "\n";
        echo "  Trace: " . $e->getFile() . ':' . $e->getLine() . "\n";
        $failed++;
    }
}

$duration = round((microtime(true) - $startTime) * 1000, 2);

echo "\n\033[1;36m----------------------------------------------------\033[0m\n";
echo "Total suites: " . count($suites) . " | \033[1;32mPassed: $passed\033[0m | ";
if ($failed > 0) {
    echo "\033[1;31mFailed: $failed\033[0m\n";
    exit(1);
} else {
    echo "\033[1;32mAll test suites passed cleanly!\033[0m ($duration ms)\n";
    exit(0);
}
