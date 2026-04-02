<?php

declare(strict_types=1);

namespace SimpleSAML\Module\ubuntunet\Security;

final class Totp
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    public static function verifyCode(?string $secret, string $code, int $window = 1): bool
    {
        if (!is_string($secret) || $secret === '') {
            return false;
        }

        $normalizedCode = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($normalizedCode) !== 6) {
            return false;
        }

        $counter = intdiv(time(), 30);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals(self::generateCode($secret, $counter + $offset), $normalizedCode)) {
                return true;
            }
        }

        return false;
    }

    private static function generateCode(string $secret, int $counter): string
    {
        $secretBytes = self::base32Decode($secret);
        if ($secretBytes === null) {
            return '000000';
        }

        $counterBytes = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $counterBytes, $secretBytes, true);
        $offset = ord(substr($hash, -1)) & 0x0f;
        $segment = substr($hash, $offset, 4);
        $value = unpack('N', $segment)[1] & 0x7fffffff;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private static function base32Decode(string $secret): ?string
    {
        $normalized = strtoupper(preg_replace('/[^A-Z2-7]/i', '', $secret) ?? '');
        if ($normalized === '') {
            return null;
        }

        $buffer = 0;
        $bitsLeft = 0;
        $output = '';

        foreach (str_split($normalized) as $char) {
            $value = strpos(self::BASE32_ALPHABET, $char);
            if ($value === false) {
                return null;
            }

            $buffer = ($buffer << 5) | $value;
            $bitsLeft += 5;

            if ($bitsLeft >= 8) {
                $bitsLeft -= 8;
                $output .= chr(($buffer >> $bitsLeft) & 0xff);
            }
        }

        return $output;
    }
}
