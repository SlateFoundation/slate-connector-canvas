<?php

namespace Slate\Connectors\Canvas;

use RuntimeException;

/**
 * Decodes the body of a Canvas API response.
 *
 * Canvas answers with JSON, but whatever sits in front of it may not: a
 * gateway error page, a plain-text rate-limit response, or an empty body
 * from a dropped connection. Such a body is reported as a RuntimeException
 * carrying the HTTP status as its code, so callers handle it like any other
 * failed Canvas request.
 */
class ResponseDecoder
{
    public static $excerptLength = 200;

    /**
     * Decode a Canvas response body to an array.
     *
     * @param string|false|null $body         response body, without headers
     * @param int               $responseCode HTTP status of the response
     *
     * @throws RuntimeException when the body does not decode to an array
     *
     * @return array
     */
    public static function decode($body, $responseCode)
    {
        $body = (string) $body;
        $data = json_decode($body, true);

        if (!is_array($data)) {
            throw new RuntimeException(
                sprintf(
                    'Canvas response (code %u) was not a JSON object or array: %s',
                    $responseCode,
                    '' === trim($body) ? '(empty body)' : static::excerpt($body)
                ),
                (int) $responseCode
            );
        }

        return $data;
    }

    /**
     * Shorten a response body to one line of printable ASCII for a log message.
     *
     * @param string $body
     *
     * @return string
     */
    public static function excerpt($body)
    {
        $excerpt = trim(preg_replace('/\s+/', ' ', (string) $body));

        if (strlen($excerpt) > static::$excerptLength) {
            $excerpt = substr($excerpt, 0, static::$excerptLength).'...';
        }

        // an excerpt cut mid-character must not break JSON-encoded job logs
        return preg_replace('/[^\x20-\x7E]/', '?', $excerpt);
    }
}
