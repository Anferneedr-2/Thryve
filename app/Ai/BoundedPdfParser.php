<?php

namespace App\Ai;

use Smalot\PdfParser\Config;
use Smalot\PdfParser\Parser;
use Smalot\PdfParser\RawData\RawDataParser;

/** Aggregate limits apply while streams are decoded, before the document is materialized. */
final class BoundedPdfParser extends Parser
{
    public function __construct()
    {
        $config = new Config;
        $config->setRetainImageContent(false);
        $config->setDecodeMemoryLimit(1024 * 1024);
        $config->setMaxNestingDepth(50);
        parent::__construct([], $config);
        $this->rawDataParser = new class([], $config) extends RawDataParser
        {
            private int $decodedBytes = 0;

            private ?float $started = null;

            protected function decodeStream(string $pdfData, array $xref, array $sdic, string $stream): array
            {
                $this->guard();
                $result = parent::decodeStream($pdfData, $xref, $sdic, $stream);
                $this->decodedBytes += strlen($result[0]);
                if ($this->decodedBytes > 8 * 1024 * 1024) {
                    throw new \RuntimeException('PDF resource limit exceeded.');
                }

                return $result;
            }

            public function iterateIndirectObjects(string $pdfData, array $xref): \Generator
            {
                if (count($xref['xref'] ?? []) > 2000) {
                    throw new \RuntimeException('PDF resource limit exceeded.');
                }
                foreach (parent::iterateIndirectObjects($pdfData, $xref) as $id => $object) {
                    $this->guard();
                    yield $id => $object;
                }
            }

            private function guard(): void
            {
                $this->started ??= microtime(true);
                if (microtime(true) - $this->started > 5) {
                    throw new \RuntimeException('PDF resource limit exceeded.');
                }
            }
        };
    }
}
