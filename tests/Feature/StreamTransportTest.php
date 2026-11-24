<?php

namespace Tests\Feature;

use App\Ai\ProviderException;
use App\Ai\StreamTransport;
use Tests\TestCase;

/**
 * The real cURL transport against a tiny local streaming server. An answer must be waited for as long as it keeps coming
 * (the live site cut every answer after 60 seconds); only an explicitly configured hard cap may end it early.
 */
class StreamTransportTest extends TestCase
{
    /** @var resource|null */
    private $server;

    private int $port;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('proc_open')) {
            $this->markTestSkipped('proc_open is not available.');
        }
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $name = stream_socket_get_name($probe, false);
        $this->port = (int) substr($name, strrpos($name, ':') + 1);
        fclose($probe);

        $router = $this->tmpDir.'/router.php';
        file_put_contents($router, <<<'PHP'
<?php
header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
while (ob_get_level() > 0) { ob_end_flush(); }
ob_implicit_flush(true);
// 12 pieces, 0.5 s apart: about 6 seconds in all, like a long answer that keeps arriving
for ($i = 0; $i < 12; $i++) { echo "data: {\"choices\":[{\"delta\":{\"content\":\"x\"}}]}\n\n"; flush(); usleep(500000); }
echo "data: [DONE]\n\n";
PHP);
        $this->server = proc_open([PHP_BINARY, '-S', "127.0.0.1:{$this->port}", $router], [['pipe', 'r'], ['file', $this->tmpDir.'/server.log', 'a'], ['file', $this->tmpDir.'/server.log', 'a']], $pipes);
        for ($i = 0; $i < 40; $i++) {
            if (@fsockopen('127.0.0.1', $this->port, $errno, $errstr, 0.2)) {
                return;
            }
            usleep(100000);
        }
        $this->fail('The test server did not start.');
    }

    protected function tearDown(): void
    {
        if (is_resource($this->server)) {
            proc_terminate($this->server);
            proc_close($this->server);
        }
        parent::tearDown();
    }

    private function read(int $total, ?float &$seconds = null): string
    {
        $bytes = '';
        $started = microtime(true);
        try {
            (new StreamTransport)->read("http://127.0.0.1:{$this->port}/", [], ['stream' => true], $total, function ($b) use (&$bytes) {
                $bytes .= $b;
            }, fn () => false);
        } finally {
            $seconds = microtime(true) - $started;
        }

        return $bytes;
    }

    public function test_with_no_time_limit_a_long_answer_is_waited_for_until_it_finishes(): void
    {
        $bytes = $this->read(0, $seconds); // 0 = no limit

        $this->assertStringContainsString('[DONE]', $bytes);
        $this->assertSame(12, substr_count($bytes, '"content":"x"'));
        $this->assertGreaterThan(5.0, $seconds); // it really did take several seconds, and was not cut
    }

    public function test_a_hard_cap_that_was_configured_still_ends_an_answer_that_runs_past_it(): void
    {
        try {
            $this->read(3, $seconds);
            $this->fail('An answer past the cap should have been given up on.');
        } catch (ProviderException $e) {
            $this->assertSame('timeout', $e->category);
            $this->assertTrue($e->ambiguous, 'the request was sent, so it may still be running upstream');
        }
        $this->assertGreaterThan(2.5, $seconds);
        $this->assertLessThan(5.5, $seconds);
    }
}
