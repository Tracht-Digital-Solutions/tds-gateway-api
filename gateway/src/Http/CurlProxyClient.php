<?php
declare(strict_types=1);

namespace Tds\ApiGateway\Http;

/** cURL-backed transparent forwarder. */
final class CurlProxyClient implements ProxyClientInterface
{
    public function __construct(
        private readonly int $connectTimeout = 2,
        private readonly int $timeout = 30,
    ) {
    }

    public function send(string $method, string $url, array $headers, string $body): ProxyResponse
    {
        $responseHeaders = [];
        $ch = $this->createHandle($method, $url, $headers, $body, $responseHeaders);

        $result = curl_exec($ch);
        if ($result === false) {
            $error = curl_error($ch);
            $errno = curl_errno($ch);
            curl_close($ch);
            throw new ProxyException("Upstream request to {$url} failed: {$error}", $errno);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return new ProxyResponse($status, $responseHeaders, (string) $result);
    }

    public function sendMany(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        $multi = curl_multi_init();
        $handles = [];
        // Each handle's header callback writes into its own slot here; the
        // by-reference param in createHandle() aliases $headerStore[$key].
        $headerStore = [];
        foreach ($requests as $key => $req) {
            $headerStore[$key] = [];
            $ch = $this->createHandle(
                (string) ($req['method'] ?? 'GET'),
                (string) ($req['url'] ?? ''),
                (array) ($req['headers'] ?? []),
                (string) ($req['body'] ?? ''),
                $headerStore[$key],
            );
            $handles[$key] = $ch;
            curl_multi_add_handle($multi, $ch);
        }

        do {
            $status = curl_multi_exec($multi, $running);
            if ($running) {
                curl_multi_select($multi, 1.0);
            }
        } while ($running && $status === CURLM_OK);

        $responses = [];
        foreach ($handles as $key => $ch) {
            // No response received (connect refused/timeout) → code 0, which
            // the health check reads as "down".
            $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            $body = $code === 0 ? '' : (string) curl_multi_getcontent($ch);
            $responses[$key] = new ProxyResponse($code, $headerStore[$key], $body);
            curl_multi_remove_handle($multi, $ch);
            curl_close($ch);
        }
        curl_multi_close($multi);

        return $responses;
    }

    /**
     * Build a configured easy handle. The header callback appends each
     * response header (preserving repeats, e.g. multiple Set-Cookie) into
     * $responseHeaders, which the caller owns.
     *
     * @param array<string, string[]> $headers
     * @param array<string, string[]> $responseHeaders
     */
    private function createHandle(
        string $method,
        string $url,
        array $headers,
        string $body,
        array &$responseHeaders,
    ): \CurlHandle {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => $this->flatten($headers),
            CURLOPT_HEADERFUNCTION => static function ($_ch, string $line) use (&$responseHeaders): int {
                $len = strlen($line);
                $trimmed = trim($line);
                // Skip the status line and the terminating blank line.
                if ($trimmed === '' || stripos($trimmed, 'HTTP/') === 0) {
                    return $len;
                }
                $colon = strpos($trimmed, ':');
                if ($colon === false) {
                    return $len;
                }
                $name = trim(substr($trimmed, 0, $colon));
                $value = trim(substr($trimmed, $colon + 1));
                // Preserve repeated headers (e.g. multiple Set-Cookie).
                $responseHeaders[$name][] = $value;
                return $len;
            },
        ]);

        // Attach a body for any method that carries one; cURL sets the verb
        // via CUSTOMREQUEST regardless.
        if ($body !== '' && !in_array(strtoupper($method), ['GET', 'HEAD'], true)) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }

        return $ch;
    }

    /**
     * @param array<string, string[]> $headers
     * @return string[] "Name: value" lines
     */
    private function flatten(array $headers): array
    {
        $lines = [];
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $lines[] = $name . ': ' . $value;
            }
        }
        return $lines;
    }
}
