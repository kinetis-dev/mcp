<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Mcp\JsonObject;
use PHPUnit\Framework\TestCase;

final class JsonObjectTest extends TestCase
{
    public function test_to_array_returns_the_wrapped_properties(): void
    {
        self::assertSame(['a' => 1], new JsonObject(['a' => 1])->toArray());
        self::assertSame([], new JsonObject([])->toArray());
    }

    public function test_json_encode_faithfully_serializes_an_empty_instance_as_an_empty_object(): void
    {
        self::assertSame('{}', json_encode(new JsonObject([]), JSON_THROW_ON_ERROR));
    }

    /**
     * The real regression this class exists to prevent: without
     * JsonSerializable, json_encode() falls back to this class's public
     * properties — none, since $properties is private — silently
     * producing "{}" and losing every property, no error raised.
     */
    public function test_json_encode_faithfully_serializes_every_property_of_a_non_empty_instance(): void
    {
        $encoded = json_encode(new JsonObject(['name' => 'value', 'count' => 3]), JSON_THROW_ON_ERROR);

        self::assertSame('{"name":"value","count":3}', $encoded);
    }

    public function test_json_encode_round_trips_back_to_the_same_data(): void
    {
        $original = ['name' => 'value', 'nested' => ['a' => 1, 'b' => 2]];

        $decoded = json_decode(json_encode(new JsonObject($original), JSON_THROW_ON_ERROR), true, flags: JSON_THROW_ON_ERROR);

        self::assertSame($original, $decoded);
    }
}
