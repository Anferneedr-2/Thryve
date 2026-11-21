<?php

namespace App\Updates;

/** OpenSSL on some Windows PHP builds cannot find its config file; point it at one if the default is missing. */
final class OpenSsl
{
    /** @return array{config?: string} */
    public static function options(): array
    {
        $candidates = array_filter([
            getenv('OPENSSL_CONF') ?: null,
            dirname(PHP_BINARY).'/extras/ssl/openssl.cnf',
            dirname(PHP_BINARY, 2).'/apache/conf/openssl.cnf',
            '/etc/ssl/openssl.cnf',
            '/usr/lib/ssl/openssl.cnf',
        ]);
        foreach ($candidates as $file) {
            if (is_file($file)) {
                return ['config' => $file];
            }
        }

        return [];
    }

    /** Create an RSA key pair; returns [privatePem, publicPem]. */
    public static function newKeyPair(int $bits = 3072): array
    {
        $opts = ['private_key_bits' => $bits, 'private_key_type' => OPENSSL_KEYTYPE_RSA] + self::options();
        $res = openssl_pkey_new($opts);
        if (! $res || ! openssl_pkey_export($res, $private, null, self::options())) {
            throw new \RuntimeException('OpenSSL could not create a key.');
        }

        return [$private, openssl_pkey_get_details($res)['key']];
    }
}
