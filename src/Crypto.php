<?php
declare(strict_types=1);

namespace Leads;

/** Encrypts client API keys at rest (libsodium secretbox). Key: APP_KEY in .env, else storage/app.key. */
final class Crypto
{
    private static function key(): string
    {
        $k = (string)Env::get('APP_KEY', '');
        if ($k !== '') {
            return sodium_crypto_generichash($k, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
        }
        $file = LEADS_ROOT . '/storage/app.key';
        if (!is_file($file)) {
            file_put_contents($file, bin2hex(random_bytes(32)));
        }
        return sodium_crypto_generichash((string)file_get_contents($file), '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
    }

    public static function encrypt(string $plain): string
    {
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        return base64_encode($nonce . sodium_crypto_secretbox($plain, $nonce, self::key()));
    }

    public static function decrypt(?string $enc): ?string
    {
        if ($enc === null || $enc === '') {
            return null;
        }
        $raw = base64_decode($enc, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $out = sodium_crypto_secretbox_open(substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES), self::key());
        return $out === false ? null : $out;
    }
}
