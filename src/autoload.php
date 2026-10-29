<?php

declare(strict_types=1);

/**
 * AI-Function-Caller-PHP
 * Structured Outputs & Function Calling for OpenAI, Anthropic & Gemini
 *
 * @author    Anton Barinov <https://github.com/Anton-Barinov>
 * @license   MIT
 */

spl_autoload_register(function (string $class): void {
    $prefix = 'AiFunctionCaller\\';
    $len = strlen($prefix);
    if (strncmp($prefix, $class, $len) !== 0) {
        return;
    }

    $relativeClass = substr($class, $len);

    if (str_starts_with($relativeClass, 'Tests\\')) {
        $testRelative = substr($relativeClass, 6);
        $testFile = dirname(__DIR__) . '/tests/' . str_replace('\\', '/', $testRelative) . '.php';
        if (file_exists($testFile)) {
            require_once $testFile;
            return;
        }
    }

    $baseDir = __DIR__ . '/';
    $file = $baseDir . str_replace('\\', '/', $relativeClass) . '.php';

    if (file_exists($file)) {
        require_once $file;
    }
});
