<?php

declare(strict_types=1);

namespace AiFunctionCaller\Tests;

require_once __DIR__ . '/../src/autoload.php';

use AiFunctionCaller\Attributes\Items;
use AiFunctionCaller\Attributes\Max;
use AiFunctionCaller\Attributes\Min;
use AiFunctionCaller\Attributes\Pattern;
use AiFunctionCaller\Exceptions\ValidationException;
use AiFunctionCaller\Hydrator\Hydrator;
use DateTimeImmutable;

enum UserRole: string
{
    case ADMIN = 'admin';
    case USER = 'user';
    case GUEST = 'guest';
}

class AddressDto
{
    public function __construct(
        public readonly string $city,
        public readonly string $country
    ) {
    }
}

class ProfileDto
{
    /**
     * @param list<string> $tags
     * @param list<AddressDto> $addresses
     */
    public function __construct(
        #[Min(1)]
        public readonly int $userId,

        #[Pattern('/^[A-Za-z ]+$/')]
        public readonly string $name,

        public readonly UserRole $role,

        public readonly DateTimeImmutable $joinedAt,

        #[Items('string')]
        public readonly array $tags,

        #[Items(AddressDto::class)]
        public readonly array $addresses,

        public readonly ?string $website = null
    ) {
    }
}

class HydratorTest
{
    public function run(): void
    {
        $hydrator = new Hydrator();

        // 1. Successful Hydration
        $validJson = json_encode([
            'userId' => 42,
            'name' => 'John Doe',
            'role' => 'admin',
            'joinedAt' => '2026-05-15T10:00:00Z',
            'tags' => ['php', 'ai', 'llm'],
            'addresses' => [
                ['city' => 'Berlin', 'country' => 'Germany'],
                ['city' => 'Paris', 'country' => 'France'],
            ],
            'website' => 'https://example.com',
        ]);

        /** @var ProfileDto $profile */
        $profile = $hydrator->hydrate(ProfileDto::class, $validJson);

        assert($profile->userId === 42, 'userId must be 42');
        assert($profile->name === 'John Doe', 'name must be John Doe');
        assert($profile->role === UserRole::ADMIN, 'role must be enum ADMIN');
        assert($profile->joinedAt instanceof DateTimeImmutable, 'joinedAt must be DateTimeImmutable');
        assert($profile->joinedAt->format('Y-m-d') === '2026-05-15', 'date must match');
        assert($profile->tags === ['php', 'ai', 'llm'], 'tags must match');
        assert(count($profile->addresses) === 2, 'addresses count must be 2');
        assert($profile->addresses[0] instanceof AddressDto, 'address must be AddressDto');
        assert($profile->addresses[0]->city === 'Berlin', 'address city must be Berlin');
        assert($profile->website === 'https://example.com', 'website must match');

        // 2. Hydrate Collection
        $collection = $hydrator->hydrateCollection(AddressDto::class, [
            ['city' => 'Tokyo', 'country' => 'Japan'],
            ['city' => 'Sydney', 'country' => 'Australia'],
        ]);
        assert(count($collection) === 2, 'collection count must be 2');
        assert($collection[0]->city === 'Tokyo', 'first item city must be Tokyo');

        // 3. Validation failure
        $invalidData = [
            'userId' => 0, // Min(1) violation
            'name' => 'John123', // Pattern violation
            'role' => 'invalid_role', // Enum violation
            'joinedAt' => 'bad_date',
            'tags' => ['valid'],
            'addresses' => [],
        ];

        $caught = false;
        try {
            $hydrator->hydrate(ProfileDto::class, $invalidData);
        } catch (ValidationException $e) {
            $caught = true;
            $errors = $e->getErrors();
            assert(isset($errors['userId']), 'userId error must be caught');
            assert(isset($errors['name']), 'name error must be caught');
            assert(isset($errors['role']), 'role error must be caught');
            assert(isset($errors['joinedAt']), 'joinedAt error must be caught');
        }

        assert($caught, 'ValidationException must be thrown on invalid data');
    }
}
