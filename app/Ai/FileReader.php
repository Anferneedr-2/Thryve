<?php

namespace App\Ai;

use ZipArchive;

/**
 * Checks that an uploaded file really is what its name says and pulls the text out of it, on our own server,
 * so Hermes receives document text or validated inline images through the chat API.
 */
final class FileReader
{
    public const KINDS = ['txt', 'md', 'csv', 'docx', 'xlsx', 'pdf', 'png', 'jpg', 'jpeg'];

    /** Characters kept per file; the rest is dropped and the file is flagged as truncated. */
    public const MAX_CHARS = 40000;

    /** Refuse zip bombs: the unpacked XML we read may not exceed this. */
    private const MAX_XML_BYTES = 30 * 1024 * 1024;

    public static function kindFor(string $filename): ?string
    {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        $ext = $ext === 'markdown' ? 'md' : $ext;

        return in_array($ext, self::KINDS, true) ? $ext : null;
    }

    /**
     * @return array{text: string, truncated: bool}
     *
     * @throws \InvalidArgumentException with a message safe to show the person
     */
    public static function read(string $path, string $kind): array
    {
        if (in_array($kind, ['png', 'jpg', 'jpeg'], true)) {
            $info = @getimagesize($path);
            $expected = $kind === 'png' ? IMAGETYPE_PNG : IMAGETYPE_JPEG;
            if (! $info || $info[2] !== $expected || $info[0] * $info[1] > 16000000 || filesize($path) > 4 * 1024 * 1024) {
                throw new \InvalidArgumentException('Use a valid PNG or JPEG image up to 4 MB and 16 megapixels.');
            }

            return ['text' => '', 'truncated' => false];
        }
        $text = match ($kind) {
            'txt', 'md', 'csv' => self::plain($path),
            'docx' => self::docx($path),
            'xlsx' => self::xlsx($path),
            'pdf' => self::pdf($path),
        };
        $text = trim($text);
        if ($text === '') {
            throw new \InvalidArgumentException('No readable text was found in this file.');
        }
        $truncated = mb_strlen($text) > self::MAX_CHARS;

        return ['text' => $truncated ? mb_substr($text, 0, self::MAX_CHARS) : $text, 'truncated' => $truncated];
    }

    private static function pdf(string $path): string
    {
        if (filesize($path) > 8 * 1024 * 1024 || ! str_starts_with(file_get_contents($path, false, null, 0, 5), '%PDF-')) {
            throw new \InvalidArgumentException('Use a valid text-based PDF up to 8 MB.');
        }
        try {
            $pdf = (new BoundedPdfParser)->parseFile($path);
            $pages = $pdf->getPages();
            if (count($pages) > 100) {
                throw new \RuntimeException;
            }
            $text = '';
            foreach ($pages as $i => $page) {
                $part = trim($page->getText());
                if ($part !== '') {
                    $text .= '\n[Page '.($i + 1)."]\n".$part;
                }
                if (mb_strlen($text) > self::MAX_CHARS) {
                    break;
                }
            }
        } catch (\Throwable) {
            throw new \InvalidArgumentException('This PDF is encrypted, damaged or too complex. Use an unencrypted text-based PDF up to 100 pages.');
        }
        if (trim($text) === '') {
            throw new \InvalidArgumentException('No readable PDF text was found. Scanned PDFs need OCR; attach a PNG/JPG image or a text-based PDF instead.');
        }

        return $text;
    }

    private static function plain(string $path): string
    {
        $raw = file_get_contents($path);
        if (str_contains($raw, "\0")) {
            throw new \InvalidArgumentException('This does not look like a text file.');
        }
        $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
        if (! mb_check_encoding($raw, 'UTF-8')) {
            $raw = @mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
        }

        return $raw;
    }

    private static function zip(string $path, string $mustContain): ZipArchive
    {
        $zip = new ZipArchive;
        if ($zip->open($path, ZipArchive::RDONLY) !== true || $zip->locateName($mustContain) === false) {
            throw new \InvalidArgumentException('This file is damaged or is not the type its name says.');
        }

        return $zip;
    }

    private static function part(ZipArchive $zip, string $name): ?string
    {
        $stat = $zip->statName($name);
        if ($stat === false) {
            return null;
        }
        if ($stat['size'] > self::MAX_XML_BYTES) {
            throw new \InvalidArgumentException('This file is too complex to read.');
        }

        return $zip->getFromName($name) ?: null;
    }

    private static function xml(string $data): \DOMDocument
    {
        $doc = new \DOMDocument;
        // LIBXML_NONET and no entity loading: nothing inside the file can pull in other files or addresses.
        if (! @$doc->loadXML($data, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
            throw new \InvalidArgumentException('This file is damaged or is not the type its name says.');
        }

        return $doc;
    }

    private static function docx(string $path): string
    {
        $zip = self::zip($path, 'word/document.xml');
        $doc = self::xml(self::part($zip, 'word/document.xml') ?? '');
        $zip->close();
        $xp = new \DOMXPath($doc);
        $xp->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $out = [];
        foreach ($xp->query('//w:p') as $p) {
            $line = '';
            foreach ($xp->query('.//w:t|.//w:tab|.//w:br', $p) as $n) {
                $line .= match ($n->localName) {
                    't' => $n->textContent,
                    'tab' => "\t",
                    default => "\n",
                };
            }
            $out[] = $line;
        }

        return implode("\n", $out);
    }

    private static function xlsx(string $path): string
    {
        $zip = self::zip($path, 'xl/workbook.xml');
        $ns = 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';

        $shared = [];
        if ($data = self::part($zip, 'xl/sharedStrings.xml')) {
            $xp = new \DOMXPath(self::xml($data));
            $xp->registerNamespace('s', $ns);
            foreach ($xp->query('//s:si') as $si) {
                $shared[] = implode('', array_map(fn ($t) => $t->textContent, iterator_to_array($xp->query('.//s:t', $si))));
            }
        }

        $names = [];
        $wb = new \DOMXPath(self::xml(self::part($zip, 'xl/workbook.xml') ?? ''));
        $wb->registerNamespace('s', $ns);
        foreach ($wb->query('//s:sheets/s:sheet') as $i => $sheet) {
            $names[$i + 1] = $sheet->getAttribute('name');
        }

        $out = [];
        $budget = self::MAX_CHARS * 2; // stop reading big workbooks early
        for ($i = 1; $i <= 50 && $budget > 0; $i++) {
            $data = self::part($zip, "xl/worksheets/sheet{$i}.xml");
            if ($data === null) {
                continue;
            }
            $xp = new \DOMXPath(self::xml($data));
            $xp->registerNamespace('s', $ns);
            $out[] = '## Sheet: '.($names[$i] ?? "Sheet {$i}");
            foreach ($xp->query('//s:sheetData/s:row') as $row) {
                $cells = [];
                foreach ($xp->query('s:c', $row) as $c) {
                    $v = $xp->query('s:v', $c)->item(0)?->textContent;
                    $cells[] = match ($c->getAttribute('t')) {
                        's' => $shared[(int) $v] ?? '',
                        'inlineStr' => $xp->query('.//s:t', $c)->item(0)?->textContent ?? '',
                        default => $v ?? '',
                    };
                }
                $line = implode("\t", $cells);
                $out[] = $line;
                $budget -= strlen($line) + 1;
                if ($budget <= 0) {
                    break;
                }
            }
        }
        $zip->close();

        return implode("\n", $out);
    }
}
