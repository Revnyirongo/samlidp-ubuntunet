<?php

declare(strict_types=1);

namespace App\Service;

final class TotpService
{
    private const BASE32_ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    private const DIGITS = 6;
    private const PERIOD = 30;

    public function generateSecret(int $length = 32): string
    {
        $secret = '';
        $alphabetLength = strlen(self::BASE32_ALPHABET);

        for ($i = 0; $i < $length; $i++) {
            $secret .= self::BASE32_ALPHABET[random_int(0, $alphabetLength - 1)];
        }

        return $secret;
    }

    public function verifyCode(?string $secret, string $code, int $window = 2): bool
    {
        if (!is_string($secret) || $secret === '') {
            return false;
        }

        $normalizedCode = preg_replace('/\D+/', '', $code) ?? '';
        if (strlen($normalizedCode) !== self::DIGITS) {
            return false;
        }

        $counter = intdiv(time(), self::PERIOD);
        for ($offset = -$window; $offset <= $window; $offset++) {
            if (hash_equals($this->generateCode($secret, $counter + $offset), $normalizedCode)) {
                return true;
            }
        }

        return false;
    }

    public function getProvisioningUri(string $accountLabel, string $secret, string $issuer): string
    {
        $label = rawurlencode($issuer . ':' . $accountLabel);
        $issuerEncoded = rawurlencode($issuer);
        $secretEncoded = rawurlencode($secret);

        return sprintf(
            'otpauth://totp/%s?secret=%s&issuer=%s&algorithm=SHA1&digits=%d&period=%d',
            $label,
            $secretEncoded,
            $issuerEncoded,
            self::DIGITS,
            self::PERIOD
        );
    }

    private function generateCode(string $secret, int $counter): string
    {
        $secretBytes = $this->base32Decode($secret);
        if ($secretBytes === null) {
            return str_repeat('0', self::DIGITS);
        }

        $counterBytes = pack('N2', ($counter >> 32) & 0xffffffff, $counter & 0xffffffff);
        $hash = hash_hmac('sha1', $counterBytes, $secretBytes, true);
        $offset = ord(substr($hash, -1)) & 0x0f;
        $segment = substr($hash, $offset, 4);
        $value = unpack('N', $segment)[1] & 0x7fffffff;

        return str_pad((string) ($value % (10 ** self::DIGITS)), self::DIGITS, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $secret): ?string
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
