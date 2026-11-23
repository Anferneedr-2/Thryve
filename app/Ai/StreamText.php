<?php

namespace App\Ai;

/** Only human-facing prose may leave the server before file validation finishes. */
final class StreamText
{
    public static function visible(string $text, bool $final = false): string
    {
        if (preg_match('/```[ \t]*thryve-file\b/i', $text, $match, PREG_OFFSET_CAPTURE)) {
            $text = substr($text, 0, $match[0][1]);
        } elseif (preg_match('/```([^\r\n]*)$/', $text, $match, PREG_OFFSET_CAPTURE) && str_starts_with('thryve-file', strtolower(trim($match[1][0])))) {
            $text = substr($text, 0, $match[0][1]);
        }

        return $final ? rtrim($text) : mb_strcut($text, 0, max(0, strlen($text) - 32), 'UTF-8');
    }
}
