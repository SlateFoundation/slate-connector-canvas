<?php

namespace Slate\Connectors\Canvas\Tests;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Slate\Connectors\Canvas\ResponseDecoder;

class ResponseDecoderTest extends TestCase
{
    public function testJsonObjectIsDecoded()
    {
        $this->assertSame(
            ['id' => 101, 'name' => 'Biology'],
            ResponseDecoder::decode('{"id":101,"name":"Biology"}', 200)
        );
    }

    public function testJsonListIsDecoded()
    {
        $this->assertSame(
            [['id' => 1], ['id' => 2]],
            ResponseDecoder::decode('[{"id":1},{"id":2}]', 200)
        );
    }

    public function testEmptyJsonListIsDecoded()
    {
        $this->assertSame([], ResponseDecoder::decode('[]', 200));
    }

    public function testJsonErrorBodyIsDecodedForTheStatusCheck()
    {
        $this->assertSame(
            ['errors' => [['message' => 'user not authorized to perform that action']]],
            ResponseDecoder::decode('{"errors":[{"message":"user not authorized to perform that action"}]}', 401)
        );
    }

    public function testHtmlErrorPageThrowsWithStatusAndOneLineExcerpt()
    {
        $html = "<html>\r\n<head><title>502 Bad Gateway</title></head>\r\n<body>\n<center><h1>502 Bad Gateway</h1></center>\n</body>\n</html>\n";

        $e = $this->decodeFailure($html, 502);

        $this->assertSame(502, $e->getCode());
        $this->assertStringContainsString('code 502', $e->getMessage());
        $this->assertStringContainsString('<title>502 Bad Gateway</title>', $e->getMessage());
        $this->assertStringNotContainsString("\n", $e->getMessage());
        $this->assertStringNotContainsString("\r", $e->getMessage());
    }

    public function testPlainTextRateLimitThrows()
    {
        $e = $this->decodeFailure("403 Forbidden (Rate Limit Exceeded)\n", 403);

        $this->assertSame(403, $e->getCode());
        $this->assertStringContainsString('403 Forbidden (Rate Limit Exceeded)', $e->getMessage());
    }

    public function testEmptyBodyThrows()
    {
        $e = $this->decodeFailure('', 200);

        $this->assertSame(200, $e->getCode());
        $this->assertStringContainsString('(empty body)', $e->getMessage());
    }

    public function testFailedTransferThrowsWithCodeZero()
    {
        // curl_exec() returns false when the connection drops, and no status is received
        $e = $this->decodeFailure(false, 0);

        $this->assertSame(0, $e->getCode());
        $this->assertStringContainsString('(empty body)', $e->getMessage());
    }

    /**
     * @dataProvider nonArrayJsonProvider
     */
    public function testJsonThatIsNotAnArrayThrows($body)
    {
        $e = $this->decodeFailure($body, 200);

        $this->assertStringContainsString($body, $e->getMessage());
    }

    public function nonArrayJsonProvider()
    {
        return [
            'string' => ['"ok"'],
            'number' => ['42'],
            'true' => ['true'],
            'null' => ['null'],
        ];
    }

    public function testLongBodyIsCutTo200Characters()
    {
        $e = $this->decodeFailure(str_repeat('x', 5000), 500);

        $this->assertStringContainsString(str_repeat('x', 200).'...', $e->getMessage());
        $this->assertStringNotContainsString(str_repeat('x', 201), $e->getMessage());
    }

    public function testExcerptIsPrintableAscii()
    {
        // a multibyte character cut in half, plus control characters
        $excerpt = ResponseDecoder::excerpt("caf\xC3\xA9 \x00\x07ok\xC3");

        $this->assertSame('caf?? ??ok?', $excerpt);
        $this->assertNotFalse(json_encode($excerpt));
    }

    private function decodeFailure($body, $responseCode)
    {
        try {
            ResponseDecoder::decode($body, $responseCode);
        } catch (RuntimeException $e) {
            return $e;
        }

        $this->fail('Expected a RuntimeException for a body that is not a JSON array or object');
    }
}
