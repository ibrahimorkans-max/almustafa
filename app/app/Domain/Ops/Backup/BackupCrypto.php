<?php

declare(strict_types=1);

namespace App\Domain\Ops\Backup;

use RuntimeException;

/**
 * Streaming authenticated encryption for backup archives (libsodium secretstream,
 * XChaCha20-Poly1305). Any change to the file — or a wrong key — makes decryption fail; a
 * truncated file is detected because the last chunk must carry the FINAL tag.
 *
 * Format: "RMBAK1" | 24-byte stream header | chunks of [4-byte length | ciphertext].
 */
final class BackupCrypto
{
    private const MAGIC = 'RMBAK1';

    private const CHUNK = 65536;

    public static function key(string $base64): string
    {
        $key = base64_decode($base64, true);
        if ($key === false || strlen($key) !== SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_KEYBYTES) {
            throw new RuntimeException('The backup key must be 32 random bytes, base64-encoded (RICEMILL_BACKUP_KEY).');
        }

        return $key;
    }

    public static function newKey(): string
    {
        return base64_encode(sodium_crypto_secretstream_xchacha20poly1305_keygen());
    }

    public static function encryptFile(string $plainPath, string $cipherPath, string $key): void
    {
        $in = fopen($plainPath, 'rb') ?: throw new RuntimeException('Cannot read the archive.');
        $out = fopen($cipherPath, 'wb') ?: throw new RuntimeException('Cannot write the backup.');
        [$state, $header] = sodium_crypto_secretstream_xchacha20poly1305_init_push($key);
        fwrite($out, self::MAGIC.$header);

        $size = (int) filesize($plainPath);
        $done = 0;
        do {
            $chunk = (string) fread($in, self::CHUNK);
            $done += strlen($chunk);
            $tag = $done >= $size ? SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL : SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_MESSAGE;
            $cipher = sodium_crypto_secretstream_xchacha20poly1305_push($state, $chunk, '', $tag);
            fwrite($out, pack('N', strlen($cipher)).$cipher);
        } while ($done < $size);

        fclose($in);
        fclose($out);
        sodium_memzero($state);
    }

    public static function decryptFile(string $cipherPath, string $plainPath, string $key): void
    {
        $in = fopen($cipherPath, 'rb') ?: throw new RuntimeException('Cannot read the backup.');
        $out = fopen($plainPath, 'wb') ?: throw new RuntimeException('Cannot write the archive.');
        try {
            if (fread($in, strlen(self::MAGIC)) !== self::MAGIC) {
                throw new RuntimeException('Not a Rice Mill ERP backup.');
            }
            $header = (string) fread($in, SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_HEADERBYTES);
            $state = sodium_crypto_secretstream_xchacha20poly1305_init_pull($header, $key);
            $final = false;
            while (! $final) {
                $len = fread($in, 4);
                if ($len === false || strlen($len) !== 4) {
                    throw new RuntimeException('The backup is truncated.');
                }
                $cipher = (string) fread($in, (int) unpack('N', $len)[1]);
                $result = sodium_crypto_secretstream_xchacha20poly1305_pull($state, $cipher);
                if ($result === false) {
                    throw new RuntimeException('The backup cannot be decrypted: wrong key or the file was changed.');
                }
                [$plain, $tag] = $result;
                fwrite($out, $plain);
                $final = $tag === SODIUM_CRYPTO_SECRETSTREAM_XCHACHA20POLY1305_TAG_FINAL;
            }
            if (! feof($in) && fread($in, 1) !== '') {
                throw new RuntimeException('Unexpected data after the end of the backup.');
            }
        } finally {
            fclose($in);
            fclose($out);
        }
    }
}
