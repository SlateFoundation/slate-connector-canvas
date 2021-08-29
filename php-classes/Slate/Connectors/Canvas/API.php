<?php

namespace Slate\Connectors\Canvas;

use Emergence\Http\Message\Request;
use Psr\Http\Message\MessageInterface;

class API extends \RemoteSystems\Canvas
{
    // TODO: migrate RemoteSystems\Canvas here and leave a shim there

    public static function buildAndExecuteRequest($method, $path, array $params = null)
    {
        $request = static::buildRequest($method, $path, $params);

        return static::execute($request);
    }

    public static function buildRequest($method, $path, array $params = null)
    {
        // build method
        $method = strtolower($method);

        // build url
        $url = sprintf('https://%s/api/v1/%s', static::$canvasHost, $path);

        if ('get' == $method && !empty($params)) {
            $url .= '?'.preg_replace('/(%5B)\d+(%5D=)/i', '$1$2', http_build_query($params));
        }

        // build headers
        $headers = [];

        // build body
        $body = null;

        if ('get' != $method && !empty($params)) {
            $headers['Content-Type'] = 'application/x-www-form-urlencoded';
            $body = http_build_query($params);
        }

        // return PSR-7 request
        return new Request($method, $url, $headers, $body);
    }

    public static function execute(MessageInterface $Request)
    {
        // confugre cURL
        static $ch;

        if (!$ch) {
            $ch = curl_init();

            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                sprintf('Authorization: Bearer %s', static::$apiToken),
            ]);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
            curl_setopt($ch, CURLOPT_HEADER, true);
        }

        // add auth header
        $Request = $Request->withAddedHeader(
            'Authorization',
            sprintf('Bearer %s', static::$apiToken)
        );

        // (re)configure cURL for this request
        curl_setopt($ch, CURLOPT_POST, 'post' == strtolower($Request->getMethod()));
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $Request->getMethod());

        if ('get' == strtolower($Request->getMethod())) {
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        } else {
            curl_setopt($ch, CURLOPT_POSTFIELDS, (string) $Request->getBody());
        }

        curl_setopt($ch, CURLOPT_URL, (string) $Request->getUri());

        $headers = [];
        foreach ($Request->getHeaders() as $name => $values) {
            $headers[] = is_array($values)
                ? sprintf('%s: %s', $name, join(', ', $values))
                : $headers[] = sprintf('%s: %s', $name, (string) $values);
        }
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

        // log request
        if (static::$logger) {
            static::$logger->debug("{$Request->getMethod()}\t{$Request->getUri()->getPath()}\t?{$Request->getUri()->getQuery()}");
        }

        // fetch pages
        $responseData = [];
        do {
            $response = curl_exec($ch);
            $responseCode = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

            $responseHeadersSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $responseHeaders = substr($response, 0, $responseHeadersSize);
            $responseData = array_merge($responseData, json_decode(substr($response, $responseHeadersSize), true));

            if ($responseCode >= 400 || $responseCode < 200) {
                $errorMessage = null;

                if (!empty($responseData['errors'])) {
                    if (!empty($responseData['errors'][0])) {
                        $errorMessage = $responseData['errors'][0]['message'];
                    } else {
                        foreach ($responseData['errors'] as $attribute => $errorData) {
                            $errorMessage = "{$errorData[0]['message']} ({$attribute})";
                            break;
                        }
                    }
                }

                throw new \RuntimeException(
                    (
                        $errorMessage
                        ? "Canvas reports: {$errorMessage}"
                        : "Canvas request failed with code {$responseCode}"
                    ),
                    $responseCode
                );
            }

            if (
                preg_match('/^link:\s+(.+?)\r\n/mi', $responseHeaders, $responseHeaderMatches)
                && !empty($responseHeaderMatches[1])
            ) {
                $responseHeaderLinks = preg_split('/\s*,\s*/', $responseHeaderMatches[1]);

                foreach ($responseHeaderLinks as $linkLine) {
                    $linkSegments = preg_split('/\s*;\s*/', $linkLine);
                    $linkUrl = substr(array_shift($linkSegments), 1, -1);

                    foreach ($linkSegments as $linkSegment) {
                        if (preg_match('/rel=([\'"]?)next\1/i', $linkSegment)) {
                            curl_setopt($ch, CURLOPT_URL, $linkUrl);
                            // continue to top-most do-loop to load new URL
                            continue 3;
                        }
                    }
                }

                // no link header or next link found
                break;
            } else {
                // no paging, finish after first request
                break;
            }
        } while (true);

        return $responseData;
    }
}
