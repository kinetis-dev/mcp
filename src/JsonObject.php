<?php

declare(strict_types=1);

namespace Kinetis\Mcp;

use JsonSerializable;

/**
 * An explicit "this is a JSON object" marker for a caller building a
 * message array directly rather than through JsonRpcCodec::decode() —
 * `Kernel`'s own #[Body] hydration and every other array-based Kinetis
 * API has no such type because a plain PHP array is already unambiguous
 * there, but a bare `[]` is genuinely ambiguous for the JSON-RPC "named
 * object" fields JsonRpcCodec::isStrictJsonObject() validates
 * (`params`/`_meta`/`clientCapabilities`/`arguments`): it's the same
 * literal a caller writes for both an empty JSON *object* (`{}`, valid)
 * and an empty JSON *array* (`[]`, invalid where an object is required).
 * `json_decode()` never has this problem — it decodes the two to a
 * `stdClass` and a PHP array respectively, distinguishable even when
 * both are empty — but nothing in the PHP language gives a hand-built
 * array literal the same two distinct empty shapes. Wrapping a value
 * in `JsonObject` says, unambiguously, "treat this as an object even
 * if it has no properties," matching what a real `{}` on the wire
 * would decode to.
 *
 * Implements JsonSerializable specifically so this stays safe to
 * `json_encode()` even though it's never meant to be — every real
 * message this codebase ever sends over the wire is built from plain
 * arrays passed through the standard `json_encode()`/`json_decode()`
 * pair (see JsonRpcCodec's own docblock: `JsonObject` is the direct-
 * array-boundary escape hatch, not a wire format). Without
 * `jsonSerialize()`, `json_encode()` would fall back to serializing this
 * class's public properties — none, since `$properties` is private —
 * silently producing `{}` regardless of what was actually wrapped and
 * losing every property a caller passed in without any error. Any
 * string-keyed value valid as a PHP array key is a valid property here;
 * PHP's `(object)` cast maps it onto a JSON object member name exactly
 * the way `json_encode()` already would for any other associative array.
 */
final readonly class JsonObject implements JsonSerializable
{
    /**
     * @param array<string, mixed> $properties
     */
    public function __construct(private array $properties = []) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->properties;
    }

    /**
     * (object) cast, not json_decode(json_encode(...)) or a manual
     * stdClass build: it round-trips an empty array to an empty object
     * ({}) and a non-empty one to a matching object with every property
     * intact, in one call, with no risk of a nested value being
     * double-encoded.
     */
    #[\Override]
    public function jsonSerialize(): object
    {
        return (object) $this->properties;
    }
}
