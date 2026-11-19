<?php

namespace App\Ai;

/** Cheap, local checks for data people should not paste into an AI tool. Returns labels only, never the matched text. */
final class SensitiveData
{
    /** @return list<string> */
    public static function find(string $text): array
    {
        $found = [];
        if (preg_match_all('/\b(?:\d[ -]?){13,19}\b/', $text, $m)) {
            foreach ($m[0] as $candidate) {
                if (self::luhn(preg_replace('/\D/', '', $candidate))) {
                    $found[] = 'a payment card number';
                    break;
                }
            }
        }
        if (preg_match('/\b\d{3}-\d{2}-\d{4}\b/', $text)) {
            $found[] = 'a government ID number';
        }
        if (preg_match('/\b(password|passcode|passwd|api[_ -]?key|secret[_ -]?key|private key)\b\s*(is|:|=)/i', $text) || str_contains($text, '-----BEGIN')) {
            $found[] = 'a password or secret key';
        }

        return array_values(array_unique($found));
    }

    private static function luhn(string $digits): bool
    {
        $len = strlen($digits);
        if ($len < 13 || $len > 19) {
            return false;
        }
        $sum = 0;
        foreach (array_reverse(str_split($digits)) as $i => $d) {
            $n = (int) $d * ($i % 2 ? 2 : 1);
            $sum += $n > 9 ? $n - 9 : $n;
        }

        return $sum % 10 === 0;
    }
}
