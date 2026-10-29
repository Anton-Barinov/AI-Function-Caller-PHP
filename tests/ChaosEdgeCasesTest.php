<?php

declare(strict_types=1);

namespace AiFunctionCaller\Tests;

require_once __DIR__ . '/../src/autoload.php';

use AiFunctionCaller\Attributes\Field;
use AiFunctionCaller\Attributes\Max;
use AiFunctionCaller\Attributes\Min;
use AiFunctionCaller\Exceptions\HydrationException;
use AiFunctionCaller\Exceptions\SchemaException;
use AiFunctionCaller\Exceptions\ValidationException;
use AiFunctionCaller\Hydrator\Hydrator;
use AiFunctionCaller\Schema\SchemaGenerator;

// Circular DTOs for testing
class CircularParentDto
{
    public function __construct(
        public readonly string $name,
        public readonly ?CircularChildDto $child = null
    ) {
    }
}

class CircularChildDto
{
    public function __construct(
        public readonly string $title,
        public readonly CircularParentDto $parent
    ) {
    }
}

enum SampleStatusEnum: string
{
    case ACTIVE = 'active';
    case ARCHIVED = 'archived';
}

class Utf8StringDto
{
    public function __construct(
        #[Min(3)]
        #[Max(10)]
        public readonly string $multibyteText,

        public readonly SampleStatusEnum $status
    ) {
    }
}

class ChaosEdgeCasesTest
{
    public function run(): void
    {
        $schemaGen = new SchemaGenerator();
        $hydrator = new Hydrator();

        // 1. Circular dependency in SchemaGenerator
        $caughtCycle = false;
        try {
            $schemaGen->generate(CircularParentDto::class);
        } catch (SchemaException $e) {
            $caughtCycle = true;
            assert(str_contains($e->getMessage(), 'Circular dependency detected'), 'Message must identify circular dependency');
        }
        assert($caughtCycle, 'SchemaGenerator must catch circular DTO dependencies without crashing or stack overflow');

        // 2. Truncated / malformed JSON in Hydrator
        $truncatedJson = '{"multibyteText": "Привет';
        $caughtMalformed = false;
        try {
            $hydrator->hydrate(Utf8StringDto::class, $truncatedJson);
        } catch (HydrationException $e) {
            $caughtMalformed = true;
            assert(str_contains($e->getMessage(), 'Failed to decode JSON'), 'Message must indicate JSON decode failure');
        }
        assert($caughtMalformed, 'Hydrator must catch truncated JSON cleanly');

        // 3. Multibyte length validation (Cyrillic & Emoji)
        // 'Привет' has 6 characters (12 bytes in UTF-8). Min is 3, Max is 10.
        $validUtf8 = $hydrator->hydrate(Utf8StringDto::class, [
            'multibyteText' => 'Привет 🚀', // 8 chars (15 bytes)
            'status' => 'active',
        ]);
        assert($validUtf8->multibyteText === 'Привет 🚀', 'Multibyte string preserved');
        assert($validUtf8->status === SampleStatusEnum::ACTIVE, 'Enum parsed');

        // Multibyte string exceeding 10 characters: 'Привет мир дорогая' has 18 characters
        $caughtTooLong = false;
        try {
            $hydrator->hydrate(Utf8StringDto::class, [
                'multibyteText' => 'Привет мир дорогая',
                'status' => 'active',
            ]);
        } catch (ValidationException $e) {
            $caughtTooLong = true;
            $errs = $e->getErrors();
            assert(isset($errs['multibyteText']), 'multibyteText error caught');
        }
        assert($caughtTooLong, 'ValidationException must be thrown when mb_strlen exceeds Max');

        // 4. BackedEnum with invalid value
        $caughtBadEnum = false;
        try {
            $hydrator->hydrate(Utf8StringDto::class, [
                'multibyteText' => 'Тест',
                'status' => 'ACTIVE', // uppercase mismatch
            ]);
        } catch (ValidationException $e) {
            $caughtBadEnum = true;
            $errs = $e->getErrors();
            assert(isset($errs['status']), 'status enum error caught');
        }
        assert($caughtBadEnum, 'Case-sensitive enum mismatch must trigger ValidationException');
    }
}
