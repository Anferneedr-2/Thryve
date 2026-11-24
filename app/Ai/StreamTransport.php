<?php

namespace App\Ai;

/** cURL callbacks allow Stop to interrupt waiting for headers as well as reading deltas. */
class StreamTransport
{
    /**
     * @param  int  $timeout  the longest the whole answer may take, in seconds; 0 waits for as long as Hermes keeps the connection
     *                        open. A dead connection (the other side vanished) is still detected through TCP keep-alive.
     */
    public function read(string $url, array $headers, array $payload, int $timeout, callable $receive, callable $stop): bool
    {
        $handle = curl_init($url);
        $status = 0;
        $type = '';
        $error = null;
        $cancelled = false;
        try {
            curl_setopt_array($handle, [CURLOPT_POST => true, CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
                CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: text/event-stream'], $headers),
                CURLOPT_CONNECTTIMEOUT => 5, CURLOPT_TIMEOUT => $timeout, CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_NOPROGRESS => false, CURLOPT_ENCODING => '',
                CURLOPT_XFERINFOFUNCTION => function () use ($stop, &$cancelled) {
                    if ($stop()) {
                        $cancelled = true;

                        return 1;
                    }

                    return 0;
                },
                CURLOPT_HEADERFUNCTION => function ($curl, $line) use (&$status, &$type) {
                    if (preg_match('#^HTTP/\S+\s+(\d+)#i', $line, $m)) {
                        $status = (int) $m[1];
                        $type = '';
                    } elseif (stripos($line, 'Content-Type:') === 0) {
                        $type = strtolower(trim(substr($line, 13)));
                    }

                    return strlen($line);
                },
                CURLOPT_WRITEFUNCTION => function ($curl, $bytes) use (&$error, &$cancelled, &$status, &$type, $receive, $stop) {
                    if ($stop()) {
                        $cancelled = true;

                        return 0;
                    }
                    if ($status !== 200 || ! str_contains($type, 'text/event-stream')) {
                        return strlen($bytes);
                    }
                    try {
                        $receive($bytes);
                    } catch (ProviderException $e) {
                        $error = $e;

                        return 0;
                    }

                    return strlen($bytes);
                },
            ]);
            // No time limit does not mean waiting for a dead server forever: probe an idle connection so a vanished peer is noticed
            // using the operating system's keep-alive failure detection, while a slow but alive answer is never cut.
            curl_setopt_array($handle, [CURLOPT_TCP_KEEPALIVE => 1, CURLOPT_TCP_KEEPIDLE => 60, CURLOPT_TCP_KEEPINTVL => 30]);
            if ($ca = ini_get('curl.cainfo')) {
                curl_setopt($handle, CURLOPT_CAINFO, $ca);
            }
            $ok = curl_exec($handle);
            $errno = curl_errno($handle);
            $sent = curl_getinfo($handle, CURLINFO_REQUEST_SIZE) > 0;
            if ($error) {
                throw $error;
            }
            if ($cancelled) {
                return true;
            }
            if ($ok === false) {
                throw new ProviderException($errno === CURLE_OPERATION_TIMEDOUT ? 'timeout' : 'unavailable', ambiguous: $sent);
            }
            if ($status !== 200) {
                throw new ProviderException(match ($status) {
                    401,403 => 'unauthorized',429 => 'rate_limited',default => 'unavailable'
                }, ambiguous: $status >= 500);
            }
            if (! str_contains($type, 'text/event-stream')) {
                throw new ProviderException('bad_response', ambiguous: true);
            }

            return false;
        } finally {
            curl_close($handle);
        }
    }
}
