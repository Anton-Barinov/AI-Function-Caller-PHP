<?php

declare(strict_types=1);

namespace AiFunctionCaller\Tests;

require_once __DIR__ . '/../src/autoload.php';

use AiFunctionCaller\Attributes\Description;
use AiFunctionCaller\Attributes\Field;
use AiFunctionCaller\Attributes\Items;
use AiFunctionCaller\Attributes\Max;
use AiFunctionCaller\Attributes\Min;
use AiFunctionCaller\Attributes\Optional;
use AiFunctionCaller\Attributes\Pattern;
use AiFunctionCaller\Schema\SchemaGenerator;

enum PriorityEnum: string
{
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
}

#[Description('Sample User DTO for tests')]
class SampleUserDto
{
    public function __construct(
        #[Field('User unique identifier')]
        #[Min(1)]
        public readonly int $id,

        #[Field('User username')]
        #[Pattern('/^[a-z0-9_]{3,16}$/')]
        public readonly string $username,

        #[Field('Optional biography')]
        public readonly ?string $bio = null,

        #[Field('Account priority')]
        public readonly PriorityEnum $priority = PriorityEnum::MEDIUM
    ) {
    }
}

class SampleContainerDto
{
    /**
     * @param list<SampleUserDto> $users
     */
    public function __construct(
        #[Field('Container title')]
        public readonly string $title,

        #[Field('List of users')]
        #[Items(SampleUserDto::class)]
        public readonly array $users,

        #[Optional]
        public readonly ?string $extra = null
    ) {
    }
}

class SchemaGeneratorTest
{
    public function run(): void
    {
        $gen = new SchemaGenerator();

        // 1. Test DTO schema generation
        $schema = $gen->generate(SampleUserDto::class, strict: true);

        assert($schema['type'] === 'object', 'Root schema must be object');
        assert($schema['description'] === 'Sample User DTO for tests', 'Class description must be populated');
        assert(isset($schema['properties']['id']), 'Property id must exist');
        assert($schema['properties']['id']['type'] === 'integer', 'id must be integer');
        assert($schema['properties']['id']['minimum'] === 1, 'id minimum must be 1');
        assert($schema['properties']['username']['pattern'] === '/^[a-z0-9_]{3,16}$/', 'pattern must match');
        assert(in_array('id', $schema['required'], true), 'id must be required');
        assert(in_array('username', $schema['required'], true), 'username must be required');
        assert($schema['additionalProperties'] === false, 'Strict schema must have additionalProperties false');

        // Enum check
        $prio = $schema['properties']['priority'];
        assert($prio['type'] === 'string', 'Priority must be string');
        assert($prio['enum'] === ['low', 'medium', 'high'], 'Enum cases must match');

        // Nullable check
        $bio = $schema['properties']['bio'];
        assert($bio['type'] === ['string', 'null'], 'Nullable bio must be [string, null]');

        // 2. Nested DTO with collections
        $containerSchema = $gen->generate(SampleContainerDto::class, strict: true);
        assert($containerSchema['properties']['users']['type'] === 'array', 'users must be array');
        assert($containerSchema['properties']['users']['items']['type'] === 'object', 'items must be object');
        assert(!in_array('extra', $containerSchema['required'], true), 'Optional field extra must not be required');

        // 3. Callable schema generation
        $callableSchema = $gen->generateForCallable(
            function (string $query, int $limit = 10): array { return []; },
            toolName: 'search_database',
            description: 'Search items in the database'
        );

        assert($callableSchema['name'] === 'search_database', 'Tool name must match');
        assert($callableSchema['description'] === 'Search items in the database', 'Description must match');
        assert($callableSchema['parameters']['properties']['query']['type'] === 'string', 'query must be string');
        assert($callableSchema['parameters']['properties']['limit']['type'] === 'integer', 'limit must be integer');
        assert($callableSchema['parameters']['properties']['limit']['default'] === 10, 'limit default must be 10');
    }
}
