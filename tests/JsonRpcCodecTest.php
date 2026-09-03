<?php

declare(strict_types=1);

namespace Kinetis\Mcp\Tests;

use Kinetis\Mcp\Exception\JsonRpcException;
use Kinetis\Mcp\JsonObject;
use Kinetis\Mcp\JsonRpcCodec;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;

/**
 * The shared decode/structural-validation path McpController,
 * StdioTransport, and McpServer::handle() all rely on — tested directly
 * here so the rules themselves (not just each entry point's own use of
 * them) are pinned in one place.
 */
final class JsonRpcCodecTest extends TestCase
{
    // --- decode(): raw bytes ---

    public function test_malformed_json_syntax_is_a_parse_error(): void
    {
        $result = JsonRpcCodec::decode('not valid json');

        self::assertArrayHasKey('errorResponse', $result);
        self::assertSame(-32700, $result['errorResponse']['error']['code']);
        self::assertNull($result['errorResponse']['id']);
    }

    public static function nonObjectTopLevelJsonProvider(): iterable
    {
        yield 'null' => ['null'];
        yield 'a bare string' => ['"hello"'];
        yield 'a bare number' => ['42'];
        yield 'a bare boolean' => ['true'];
    }

    #[DataProvider('nonObjectTopLevelJsonProvider')]
    public function test_valid_json_that_is_not_an_object_is_invalid_request_not_parse_error(string $json): void
    {
        $result = JsonRpcCodec::decode($json);

        self::assertArrayHasKey('errorResponse', $result);
        self::assertSame(-32600, $result['errorResponse']['error']['code']);
        self::assertNull($result['errorResponse']['id']);
    }

    public function test_a_top_level_json_array_is_rejected_as_invalid_request_never_treated_as_a_notification(): void
    {
        $batch = json_encode([
            ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'],
            ['jsonrpc' => '2.0', 'id' => 2, 'method' => 'resources/list'],
        ]);

        $result = JsonRpcCodec::decode((string) $batch);

        self::assertArrayHasKey('errorResponse', $result, 'a batch must get a real error response, never null/202');
        self::assertSame(-32600, $result['errorResponse']['error']['code']);
        self::assertNull($result['errorResponse']['id']);
    }

    public function test_a_well_formed_request_decodes_to_a_message(): void
    {
        $result = JsonRpcCodec::decode(json_encode([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
        ]));

        self::assertArrayHasKey('message', $result);
        self::assertSame('tools/list', $result['message']['method']);
        self::assertSame(1, $result['message']['id']);
    }

    // --- validateMessage(): structural rules, shared by decode() and McpServer::handle() ---

    public function test_a_missing_jsonrpc_member_is_invalid_request(): void
    {
        $error = JsonRpcCodec::validateMessage(['id' => 1, 'method' => 'tools/list']);

        self::assertNotNull($error);
        self::assertSame(-32600, $error['error']['code']);
        self::assertSame(1, $error['id'], 'a valid id is still echoed back when only jsonrpc is wrong');
    }

    public function test_a_wrong_jsonrpc_version_is_invalid_request(): void
    {
        $error = JsonRpcCodec::validateMessage(['jsonrpc' => '1.0', 'id' => 1, 'method' => 'tools/list']);

        self::assertNotNull($error);
        self::assertSame(-32600, $error['error']['code']);
    }

    public function test_a_missing_method_is_invalid_request_not_parse_error(): void
    {
        $error = JsonRpcCodec::validateMessage(['jsonrpc' => '2.0', 'id' => 1]);

        self::assertNotNull($error);
        self::assertSame(-32600, $error['error']['code']);
    }

    public function test_a_non_string_method_is_invalid_request(): void
    {
        $error = JsonRpcCodec::validateMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 42]);

        self::assertNotNull($error);
        self::assertSame(-32600, $error['error']['code']);
    }

    /**
     * The core correction: a structurally invalid message with no `id`
     * at all is not a notification — only a *valid* request without one
     * is. This must always produce a response, never null.
     */
    public function test_an_invalid_request_missing_id_still_gets_an_error_response_with_null_id(): void
    {
        $error = JsonRpcCodec::validateMessage(['jsonrpc' => '2.0']);

        self::assertNotNull($error, 'a structurally invalid message missing id must not be treated as a notification');
        self::assertSame(-32600, $error['error']['code']);
        self::assertNull($error['id']);
    }

    public static function invalidIdProvider(): iterable
    {
        yield 'boolean' => [true];
        yield 'a list' => [[1, 2]];
        yield 'an object' => [['a' => 1]];
        yield 'a float' => [3.14];
    }

    #[DataProvider('invalidIdProvider')]
    public function test_an_id_outside_the_supported_domain_is_invalid_request_with_a_null_id(mixed $badId): void
    {
        $error = JsonRpcCodec::validateMessage(['jsonrpc' => '2.0', 'id' => $badId, 'method' => 'tools/list']);

        self::assertNotNull($error);
        self::assertSame(-32600, $error['error']['code']);
        self::assertNull($error['id'], 'an untrustworthy id can never be echoed back');
    }

    public static function validIdProvider(): iterable
    {
        yield 'string' => ['abc'];
        yield 'int' => [7];
        yield 'null' => [null];
    }

    #[DataProvider('validIdProvider')]
    public function test_every_supported_id_type_is_accepted(mixed $goodId): void
    {
        $error = JsonRpcCodec::validateMessage(['jsonrpc' => '2.0', 'id' => $goodId, 'method' => 'tools/list']);

        self::assertNull($error);
    }

    public static function malformedParamsProvider(): iterable
    {
        yield 'a scalar' => ['hello'];
        yield 'a non-empty list' => [[1, 2, 3]];
    }

    #[DataProvider('malformedParamsProvider')]
    public function test_a_present_non_object_params_is_invalid_params_not_silently_coerced(mixed $badParams): void
    {
        $error = JsonRpcCodec::validateMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => $badParams,
        ]);

        self::assertNotNull($error);
        self::assertSame(-32602, $error['error']['code']);
    }

    public function test_absent_params_is_valid(): void
    {
        self::assertNull(JsonRpcCodec::validateMessage(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list']));
    }

    /**
     * A present null is not the same as an omitted key — omitting params
     * entirely means "none given"; explicitly sending `"params": null`
     * is itself a value, and not the object-or-absent shape this method
     * requires.
     */
    public function test_a_present_null_params_is_invalid_params_not_treated_as_absent(): void
    {
        $error = JsonRpcCodec::validateMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => null,
        ]);

        self::assertNotNull($error);
        self::assertSame(-32602, $error['error']['code']);
    }

    /**
     * A bare PHP `[]` is genuinely ambiguous — it's the only spelling
     * for both an empty JSON object and an empty JSON array — so it is
     * no longer accepted at all; JsonObject is the explicit escape
     * hatch, proven separately below.
     */
    public function test_a_bare_empty_array_params_is_invalid(): void
    {
        $error = JsonRpcCodec::validateMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => [],
        ]);

        self::assertNotNull($error);
        self::assertSame(-32602, $error['error']['code']);
    }

    public function test_a_named_object_shaped_params_is_valid(): void
    {
        self::assertNull(JsonRpcCodec::validateMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => ['_meta' => ['a' => 1]],
        ]));
    }

    public function test_an_explicit_json_object_wrapping_no_properties_is_valid_params(): void
    {
        self::assertNull(JsonRpcCodec::validateMessage([
            'jsonrpc' => '2.0',
            'id' => 1,
            'method' => 'tools/list',
            'params' => new JsonObject([]),
        ]));
    }

    /**
     * The genuine wire-level distinction this class exists to preserve:
     * `{}` and `[]` decode to different PHP shapes (stdClass vs. a plain
     * array) even when both are empty, so a real request can carry an
     * empty params *object* — decode() must accept it — while an empty
     * params *array* is still rejected, through the exact same raw-bytes
     * path a real transport uses.
     */
    public function test_decode_accepts_an_empty_json_object_params_but_rejects_an_empty_json_array_params(): void
    {
        $objectResult = JsonRpcCodec::decode('{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}');
        self::assertArrayHasKey('message', $objectResult);

        $arrayResult = JsonRpcCodec::decode('{"jsonrpc":"2.0","id":1,"method":"tools/list","params":[]}');
        self::assertArrayHasKey('errorResponse', $arrayResult);
        self::assertSame(-32602, $arrayResult['errorResponse']['error']['code']);
    }

    public function test_a_structurally_valid_request_without_id_is_a_notification_and_passes(): void
    {
        self::assertNull(JsonRpcCodec::validateMessage(['jsonrpc' => '2.0', 'method' => 'tools/list']));
    }

    // --- isStrictJsonObject() ---

    public function test_strict_json_object_accepts_stdclass_json_object_and_non_empty_non_list_arrays(): void
    {
        self::assertTrue(JsonRpcCodec::isStrictJsonObject(new stdClass()));
        self::assertTrue(JsonRpcCodec::isStrictJsonObject(new JsonObject([])));
        self::assertTrue(JsonRpcCodec::isStrictJsonObject(['a' => 1]));
    }

    public function test_strict_json_object_rejects_a_bare_empty_array_lists_and_scalars(): void
    {
        self::assertFalse(JsonRpcCodec::isStrictJsonObject([]));
        self::assertFalse(JsonRpcCodec::isStrictJsonObject([1, 2, 3]));
        self::assertFalse(JsonRpcCodec::isStrictJsonObject('x'));
        self::assertFalse(JsonRpcCodec::isStrictJsonObject(42));
        self::assertFalse(JsonRpcCodec::isStrictJsonObject(true));
        self::assertFalse(JsonRpcCodec::isStrictJsonObject(null));
    }

    // --- objectGet()/objectHas() ---

    public function test_object_accessors_read_stdclass_json_object_and_plain_arrays_uniformly(): void
    {
        $stdClassNode = json_decode('{"a": 1}');

        self::assertTrue(JsonRpcCodec::objectHas($stdClassNode, 'a'));
        self::assertSame(1, JsonRpcCodec::objectGet($stdClassNode, 'a'));
        self::assertFalse(JsonRpcCodec::objectHas($stdClassNode, 'b'));
        self::assertNull(JsonRpcCodec::objectGet($stdClassNode, 'b'));

        $jsonObjectNode = new JsonObject(['a' => 1]);
        self::assertTrue(JsonRpcCodec::objectHas($jsonObjectNode, 'a'));
        self::assertSame(1, JsonRpcCodec::objectGet($jsonObjectNode, 'a'));

        self::assertTrue(JsonRpcCodec::objectHas(['a' => 1], 'a'));
        self::assertSame(1, JsonRpcCodec::objectGet(['a' => 1], 'a'));
    }

    /**
     * A present null is still detected as present — array_key_exists()/
     * property_exists() semantics, not a null-coalescing shortcut, since
     * a caller checking objectHas() before objectGet() needs to be able
     * to tell "explicitly null" apart from "not sent at all."
     */
    public function test_object_has_detects_a_present_null_value(): void
    {
        self::assertTrue(JsonRpcCodec::objectHas(['a' => null], 'a'));
        self::assertTrue(JsonRpcCodec::objectHas(json_decode('{"a": null}'), 'a'));
    }

    // --- toArrayDeep() ---

    public function test_to_array_deep_flattens_a_mixed_stdclass_json_object_and_array_tree(): void
    {
        $tree = json_decode('{"a": {"b": [1, 2, {"c": 3}]}}');

        self::assertSame(['a' => ['b' => [1, 2, ['c' => 3]]]], JsonRpcCodec::toArrayDeep($tree));
        self::assertSame(['x' => 1], JsonRpcCodec::toArrayDeep(new JsonObject(['x' => 1])));
    }

    // --- errorEnvelope() ---

    public function test_error_envelope_carries_data_only_when_the_exception_has_it(): void
    {
        $withoutData = JsonRpcCodec::errorEnvelope(1, JsonRpcException::invalidRequest());
        self::assertArrayNotHasKey('data', $withoutData['error']);

        $withData = JsonRpcCodec::errorEnvelope(1, JsonRpcException::unsupportedProtocolVersion(['2026-07-28'], '1999'));
        self::assertSame(['supported' => ['2026-07-28'], 'requested' => '1999'], $withData['error']['data']);
    }
}
