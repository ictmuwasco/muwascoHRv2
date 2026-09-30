<?php

declare(strict_types=1);

namespace Tests\Unit\Helpers;

use App\Helpers\Json;
use PHPUnit\Framework\TestCase;

/**
 * Behavioural tests for App\Helpers\Json.
 *
 * The helper exists because the codebase had 16 bare json_decode() calls whose
 * failure mode was silent: malformed input returned null, a nearby is_array()
 * guard coalesced that to an empty array, and an employee's next of kin or a
 * delegation's permission list simply vanished with no error anywhere.
 *
 * The split between decodeStored() and decodeRequest() is the important part:
 * stored data must never throw (one corrupt row cannot take down a page), while
 * request data must throw (a bad body is the client's fault, not ours).
 */
final class JsonTest extends TestCase
{
    // -----------------------------------------------------------------
    // decodeStored - stored data, never throws
    // -----------------------------------------------------------------

    public function testDecodeStoredReturnsNullForNullInput(): void
    {
        $this->assertNull(Json::decodeStored(null));
    }

    public function testDecodeStoredReturnsDefaultForEmptyAndWhitespace(): void
    {
        $this->assertSame('fallback', Json::decodeStored('', 'fallback'));
        $this->assertSame('fallback', Json::decodeStored('   ', 'fallback'));
        $this->assertSame('fallback', Json::decodeStored(null, 'fallback'));
    }

    public function testDecodeStoredDecodesValidJson(): void
    {
        $this->assertSame(['a' => 1], Json::decodeStored('{"a":1}'));
        $this->assertSame([1, 2, 3], Json::decodeStored('[1,2,3]'));
    }

    /**
     * The core guarantee. A corrupt value in a TEXT column must not throw,
     * because the caller is usually rendering a page or evaluating
     * authorization and a single bad row must not cause a 500.
     */
    public function testDecodeStoredNeverThrowsOnMalformedJson(): void
    {
        $this->assertSame('fallback', Json::decodeStored('{not json', 'fallback'));
        $this->assertSame('fallback', Json::decodeStored('{"a":', 'fallback'));
        $this->assertSame('fallback', Json::decodeStored('undefined', 'fallback'));
    }

    public function testDecodeStoredArrayCoercesNonArrayResults(): void
    {
        // A bare scalar decodes successfully but is not an array; callers
        // asking for a list must get [] rather than a scalar.
        $this->assertSame([], Json::decodeStoredArray('42'));
        $this->assertSame([], Json::decodeStoredArray('"a string"'));
        $this->assertSame([], Json::decodeStoredArray('{bad json'));
        $this->assertSame([], Json::decodeStoredArray(null));
    }

    public function testDecodeStoredArrayDecodesNestedStructures(): void
    {
        $this->assertSame(
            [['workplan_objective_id' => 7]],
            Json::decodeStoredArray('[{"workplan_objective_id":7}]')
        );
    }

    // -----------------------------------------------------------------
    // decodeRequest - client data, throws
    // -----------------------------------------------------------------

    public function testDecodeRequestReturnsNullForEmptyBody(): void
    {
        // Form posts legitimately send no body. There is no default here on
        // purpose: an absent body should read as "nothing sent" (null), and
        // callers that need [] ask for it explicitly.
        $this->assertNull(Json::decodeRequest(''));
        $this->assertNull(Json::decodeRequest(null));
        $this->assertNull(Json::decodeRequest('  '));
    }

    public function testDecodeRequestHonoursAnExplicitDefault(): void
    {
        $this->assertSame('d', Json::decodeRequest('  ', 'd'));
    }

    public function testDecodeRequestDecodesValidJson(): void
    {
        $this->assertSame(['reason' => 'x'], Json::decodeRequest('{"reason":"x"}'));
    }

    /**
     * A malformed request body must surface as an error, not be silently
     * reinterpreted as an empty payload. The global exception handler in
     * bootstrap.php turns this into a 400.
     */
    public function testDecodeRequestThrowsOnMalformedJson(): void
    {
        $this->expectException(\JsonException::class);
        Json::decodeRequest('{not json');
    }

    // -----------------------------------------------------------------
    // encode
    // -----------------------------------------------------------------

    public function testEncodeProducesJson(): void
    {
        $this->assertSame('{"a":1}', Json::encode(['a' => 1]));
    }

    public function testEncodeKeepsUnicodeReadable(): void
    {
        // Without JSON_UNESCAPED_UNICODE, non-ASCII becomes \uXXXX, which is
        // still valid JSON but unreadable in logs and stored columns.
        $this->assertStringContainsString('café', Json::encode(['n' => 'café']));
    }

    public function testEncodeThrowsOnUnencodableValue(): void
    {
        // Invalid UTF-8 makes json_encode() return false. Callers that ignore
        // the return value would persist or emit an empty string instead.
        $this->expectException(\JsonException::class);
        Json::encode(['bad' => "\xB1\x31"]);
    }
}
