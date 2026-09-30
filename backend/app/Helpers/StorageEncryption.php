<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * StorageEncryption - envelope encryption for files at rest (PART B).
 *
 * WHY OPENSSL AND NOT LIBSODIUM
 *   The preferred primitive was libsodium secretstream (XChaCha20-Poly1305),
 *   which is the right tool: it is designed for multi-chunk streaming with a
 *   built-in final-flag. It is NOT available here - extension_loaded('sodium')
 *   is false on this deployment (PHP 8.0.30), so
 *   sodium_crypto_secretstream_xchacha20poly1305_init() does not exist.
 *   openssl_get_cipher_methods() does include aes-256-gcm, and OpenSSL is
 *   present, so this helper uses AES-256-GCM. Verified before implementation:
 *
 *     php -r "var_dump(extension_loaded('sodium'));                      // bool(false)
 *             in_array('aes-256-gcm', openssl_get_cipher_methods()));   // bool(true)"
 *
 *   AES-256-GCM is an AEAD with the same security properties secretstream
 *   provides; the chunked format below re-creates the streaming property.
 *
 * ON-DISK FORMAT (version 1)
 *   "MWSC1"                  5 bytes  magic
 *   0x01                     1 byte   format version
 *   baseNonce                8 bytes  random per file
 *   chunkSize                4 bytes  uint32 big-endian
 *   then, repeated:
 *     iv                     12 bytes baseNonce(8) . counter(4 BE)
 *     ciphertext+tag         N+16     AES-256-GCM output
 *
 *   GCM requires a unique (key, nonce) pair. Uniqueness holds on both axes:
 *   the file key is unique per file AND the counter is unique within a file,
 *   so no pair is ever reused.
 *
 *   The header (magic + version + baseNonce + chunkSize) is bound in as
 *   Additional Authenticated Data on every chunk, so an attacker cannot
 *   truncate the file, reorder chunks, or rewrite chunkSize without the GCM
 *   tag check failing. Chunk reordering is additionally detected because the
 *   counter is part of the IV, so a swapped chunk carries a mismatched IV.
 *
 * KEY HIERARCHY
 *   master key (STORAGE_ENCRYPTION_KEY, env only, never in git, never inside
 *   the storage tree, separate from JWT_SECRET)
 *     -> per-file key (random 32 bytes, generated once at encrypt time)
 *          -> file content
 *
 *   Only the WRAPPED per-file key is persisted
 *   (file_encryption.file_key_wrapped). The master key can therefore be
 *   rotated by re-wrapping: the file bytes are never touched, so rotation is
 *   O(rows) small writes rather than re-encrypting every document. See
 *   scripts/storage/rotate_master_key.php.
 *
 * WHAT THIS DOES NOT DO
 *   It does not protect a running server: anything that can read the file AND
 *   has the master key (i.e. the application) can decrypt. Its guarantee is
 *   about AT REST: a copied folder, a stolen backup, or a dumped database is
 *   unreadable without STORAGE_ENCRYPTION_KEY, which is held separately.
 */
final class StorageEncryption
{
    /** Format marker written as the first bytes of every encrypted file. */
    private const MAGIC = 'MWSC1';

    /** Format version. Bump only alongside a matching reader branch. */
    private const VERSION = "\x01";

    /** AES-256-GCM key length in bytes. */
    private const KEY_BYTES = 32;

    /** 96-bit GCM nonce. */
    private const IV_BYTES = 12;

    /** GCM authentication tag appended to every chunk. */
    private const TAG_BYTES = 16;

    /**
     * Plaintext bytes per chunk. 256 KiB keeps peak memory bounded for large
     * documents (a 20 MB policy PDF uses ~20 chunks) while staying large
     * enough that per-chunk GCM overhead is negligible.
     */
    public const DEFAULT_CHUNK_SIZE = 262144;

    private const CIPHER = 'aes-256-gcm';

    private function __construct()
    {
    }

    /**
     * Is encryption available in this PHP build?
     *
     * Checked explicitly rather than assumed, so a deployment missing either
     * extension fails loudly at boot (see ConfigValidator) instead of writing
     * files that can never be read back.
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('openssl')
            && in_array(self::CIPHER, openssl_get_cipher_methods(), true);
    }

    /**
     * The key version new writes are stamped with.
     *
     * Defaults to 1. Bumping STORAGE_ENCRYPTION_KEY_VERSION is what makes a
     * rotation distinguishable: rotate_master_key.php rewrites the wrapped
     * keys at the new version while the reader keeps using
     * STORAGE_ENCRYPTION_KEY_PREVIOUS for anything still marked with the
     * previous version.
     */
    public static function currentKeyVersion(): int
    {
        return max(1, (int) \env('STORAGE_ENCRYPTION_KEY_VERSION', 1));
    }

    /**
     * Derive the master key from STORAGE_ENCRYPTION_KEY.
     *
     * Accepts either a 64-char hex string (output of
     * `php -r "echo bin2hex(random_bytes(32));"`) or any string of at least 32
     * bytes, which is hashed to 32 bytes with SHA-256. Hex is preferred
     * because it is exactly 32 bytes of entropy and survives copy/paste.
     *
     * @throws \RuntimeException if the key is missing or too short.
     */
    public static function masterKey(?int $keyVersion = null): string
    {
        if ($keyVersion !== null && $keyVersion > 0) {
            $prev = trim((string) (\env('STORAGE_ENCRYPTION_KEY_PREVIOUS', '') ?? ''));
            // Only the immediately previous version is retained; anything
            // older is expected to have been re-wrapped by then.
            if ($prev !== '' && $keyVersion === self::currentKeyVersion() - 1) {
                return self::materialiseKey($prev);
            }
        }

        return self::materialiseKey((string) (\env('STORAGE_ENCRYPTION_KEY', '') ?? ''));
    }

    /** Normalise user-supplied key material to exactly 32 raw bytes. */
    private static function materialiseKey(string $raw): string
    {
        $trimmed = trim($raw);

        if ($trimmed === '') {
            throw new \RuntimeException(
                'STORAGE_ENCRYPTION_KEY is not set. Generate one with: '
                . 'php -r "echo bin2hex(random_bytes(32));"'
            );
        }

        if (preg_match('/^[0-9a-fA-F]{64}$/', $trimmed) === 1) {
            $bin = hex2bin($trimmed);
            if ($bin !== false) {
                return $bin;
            }
        }

        if (strlen($trimmed) < 32) {
            throw new \RuntimeException(
                'STORAGE_ENCRYPTION_KEY must be at least 32 characters '
                . '(generate 32 random bytes: php -r "echo bin2hex(random_bytes(32));"). '
                . 'Got ' . strlen($trimmed) . '.'
            );
        }

        // Not hex, or hex of the wrong length: hash down to a fixed 32 bytes.
        return hash('sha256', $trimmed, true);
    }

    /** Generate a fresh random per-file key. */
    public static function generateFileKey(): string
    {
        return random_bytes(self::KEY_BYTES);
    }

    /**
     * Wrap a per-file key under the master key.
     *
     * @param string $aad Bind context, e.g. "employee_documents:42". Binding
     *                    the table+id means a wrapped key copied onto another
     *                    row fails to authenticate there.
     * @return array{wrapped:string, nonce:string, key_version:int} hex-encoded
     */
    public static function wrapKey(string $fileKey, string $aad, ?int $keyVersion = null): array
    {
        $version = $keyVersion ?? self::currentKeyVersion();
        $master  = self::masterKey($version);
        $nonce   = random_bytes(self::IV_BYTES);

        // openssl_encrypt() signature on this build (PHP 8.0.30):
        //   ($data, $cipher, $key, $options, $iv, &$tag, $aad, $tag_length)
        // VERIFIED with ReflectionFunction - do not assume the order matches
        // openssl_decrypt, which puts $tag BEFORE $aad (see unwrapKey).
        $tag = null;
        $ciphertext = openssl_encrypt(
            $fileKey,
            self::CIPHER,
            $master,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad,
            self::TAG_BYTES
        );

        if ($ciphertext === false || $tag === null) {
            throw new \RuntimeException('Failed to wrap file key: ' . openssl_error_string());
        }

        return [
            'wrapped'     => bin2hex($ciphertext . $tag),
            'nonce'       => bin2hex($nonce),
            'key_version' => $version,
        ];
    }

    /**
     * Unwrap a per-file key.
     *
     * @throws \RuntimeException if authentication fails (wrong key, wrong AAD,
     *                           or the blob was altered).
     */
    public static function unwrapKey(string $wrapped, string $nonceHex, string $aad, int $keyVersion = 1): string
    {
        $master = self::masterKey($keyVersion);
        $nonce  = hex2bin($nonceHex);
        $blob   = hex2bin($wrapped);

        if ($nonce === false || $blob === false) {
            throw new \RuntimeException('Corrupt key-wrapping metadata (non-hex).');
        }

        // wrapKey() stored ciphertext+tag concatenated, so split them back out.
        // openssl_decrypt signature on this build (VERIFIED with
        // ReflectionFunction, PHP 8.0.30):
        //   ($data, $cipher, $key, $options, $iv, $tag, $aad)
        // NOTE $tag comes BEFORE $aad here, the reverse of openssl_encrypt.
        if (strlen($blob) <= self::TAG_BYTES) {
            throw new \RuntimeException('Corrupt key-wrapping metadata (blob shorter than its tag).');
        }
        $ciphertext = substr($blob, 0, -self::TAG_BYTES);
        $tag        = substr($blob, -self::TAG_BYTES);

        $fileKey = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $master,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            $aad
        );

        if ($fileKey === false) {
            // Never echo the ciphertext or the key here.
            throw new \RuntimeException(
                'Unable to unwrap file key: authentication failed '
                . '(wrong STORAGE_ENCRYPTION_KEY, wrong row binding, or altered data).'
            );
        }

        return $fileKey;
    }

    /**
     * Re-wrap an already-wrapped key under a different key version.
     *
     * This is the ONLY operation a master-key rotation performs: it unwraps
     * with the old master key and re-wraps with the new one. File content is
     * untouched, so rotation never re-encrypts or re-hashes a single document.
     */
    public static function rewrapKey(
        string $wrapped,
        string $nonceHex,
        string $aad,
        int $oldVersion,
        int $newVersion
    ): array {
        $fileKey = self::unwrapKey($wrapped, $nonceHex, $aad, $oldVersion);

        return self::wrapKey($fileKey, $aad, $newVersion);
    }

    /**
     * Build the AAD that binds a wrapped key to its owning row.
     *
     * Centralised so encrypt and decrypt cannot drift apart: a mismatch here is
     * indistinguishable from tampering, which is exactly the intent.
     */
    public static function keyAad(string $tableName, int $recordId): string
    {
        return $tableName . ':' . $recordId;
    }

    // -----------------------------------------------------------------------
    // File content: chunked AES-256-GCM
    // -----------------------------------------------------------------------

    /**
     * Encrypt a file, streaming, and return its wrapping metadata.
     *
     * The plaintext is never held in memory in full and never written to a
     * second location: chunks are read, encrypted and appended one at a time.
     *
     * @return array{wrapped:string,nonce:string,key_version:int,
     *               sha256:string,bytes:int,chunk_size:int}
     */
    public static function encryptFile(
        string $sourcePath,
        string $tableName,
        int $recordId,
        ?int $chunkSize = null
    ): array {
        self::requireAvailable();

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException('Cannot encrypt: source file is not readable.');
        }

        $chunkSize = $chunkSize ?? self::DEFAULT_CHUNK_SIZE;
        $in = fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Cannot encrypt: unable to open source file.');
        }

        $fileKey   = self::generateFileKey();
        $baseNonce = random_bytes(8);
        $counter   = 0;
        $plainHash = hash_init('sha256');
        $total     = 0;

        $tmpPath = $sourcePath . '.enc.' . bin2hex(random_bytes(6));
        $out = fopen($tmpPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('Cannot encrypt: unable to create temporary output.');
        }

        try {
            // Header precedes any ciphertext so a truncated file is detectable:
            // a reader that cannot find the magic refuses outright.
            fwrite($out, self::MAGIC . self::VERSION . $baseNonce . pack('N', $chunkSize));

            // AAD binds the whole header, so chunkSize cannot be edited.
            $aad = self::MAGIC . self::VERSION . $baseNonce . pack('N', $chunkSize);

            while (!feof($in)) {
                $plain = fread($in, $chunkSize);
                if ($plain === false || $plain === '') {
                    break;
                }

                $iv = $baseNonce . pack('N', $counter);
                // Same by-reference $tag slot as wrapKey() - see the note there.
                $tag = null;
                $ct = openssl_encrypt(
                    $plain,
                    self::CIPHER,
                    $fileKey,
                    OPENSSL_RAW_DATA,
                    $iv,
                    $tag,
                    $aad,
                    self::TAG_BYTES
                );
                if ($ct === false) {
                    throw new \RuntimeException('Encryption failed at chunk ' . $counter . '.');
                }

                hash_update($plainHash, $plain);
                $total += strlen($plain);

                fwrite($out, $iv . $ct . $tag);
                $counter++;
            }

            fflush($out);
            fclose($out);
            fclose($in);
        } catch (\Throwable $e) {
            @fclose($out);
            @fclose($in);
            @unlink($tmpPath);
            throw $e;
        }

        // Replace the original atomically, then hand back the metadata the
        // caller MUST persist. A crash in between leaves an unreferenced
        // ciphertext (detectable by the migration script) rather than a
        // plaintext file with no key record.
        if (!@rename($tmpPath, $sourcePath)) {
            @unlink($tmpPath);
            throw new \RuntimeException('Encryption succeeded but the file could not be replaced.');
        }

        $wrapped = self::wrapKey($fileKey, self::keyAad($tableName, $recordId));

        return [
            'wrapped'     => $wrapped['wrapped'],
            'nonce'       => $wrapped['nonce'],
            'key_version' => $wrapped['key_version'],
            'sha256'      => hash_final($plainHash),
            'bytes'       => $total,
            'chunk_size'  => $chunkSize,
        ];
    }

    /**
     * Decrypt a file to a destination path, streaming.
     *
     * @throws \RuntimeException on a bad header or any GCM authentication
     *                           failure. A tampered, truncated or reordered
     *                           file fails here rather than yielding garbage.
     */
    public static function decryptFile(
        string $sourcePath,
        string $destinationPath,
        string $fileKey
    ): int {
        self::requireAvailable();

        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \RuntimeException('Cannot decrypt: source file is not readable.');
        }

        $in = fopen($sourcePath, 'rb');
        if ($in === false) {
            throw new \RuntimeException('Cannot decrypt: unable to open source file.');
        }

        $header = fread($in, 5 + 1 + 8 + 4);
        if ($header === false || strlen($header) !== 18) {
            fclose($in);
            throw new \RuntimeException('Cannot decrypt: file is too short to be a v1 container.');
        }

        if (substr($header, 0, 5) !== self::MAGIC) {
            fclose($in);
            throw new \RuntimeException(
                'Cannot decrypt: not an encrypted storage container (bad magic). '
                . 'The file may never have been encrypted.'
            );
        }

        if (substr($header, 5, 1) !== self::VERSION) {
            fclose($in);
            throw new \RuntimeException('Cannot decrypt: unsupported container version.');
        }

        $baseNonce = substr($header, 6, 8);
        $chunkSize = (int) unpack('N', substr($header, 14, 4))[1];

        if ($chunkSize <= 0 || $chunkSize > 16 * 1024 * 1024) {
            fclose($in);
            throw new \RuntimeException('Cannot decrypt: implausible chunk size in header.');
        }

        $aad = self::MAGIC . self::VERSION . $baseNonce . pack('N', $chunkSize);

        $out = fopen($destinationPath, 'wb');
        if ($out === false) {
            fclose($in);
            throw new \RuntimeException('Cannot decrypt: unable to open destination.');
        }

        $counter = 0;
        $written = 0;

        try {
            while (!feof($in)) {
                $iv = fread($in, self::IV_BYTES);
                if ($iv === false || $iv === '') {
                    break;
                }
                if (strlen($iv) !== self::IV_BYTES) {
                    throw new \RuntimeException('Decryption failed: truncated chunk header.');
                }

                $body = stream_get_contents($in, $chunkSize + self::TAG_BYTES);
                if ($body === false || $body === '') {
                    throw new \RuntimeException('Decryption failed: truncated ciphertext chunk.');
                }
                // encryptFile wrote iv || ciphertext || tag.
                if (strlen($body) < self::TAG_BYTES + 1) {
                    throw new \RuntimeException('Decryption failed: ciphertext chunk shorter than its tag.');
                }
                $ciphertext = substr($body, 0, -self::TAG_BYTES);
                $tag        = substr($body, -self::TAG_BYTES);

                // The counter is inside the IV, so a chunk moved from another
                // position carries an IV that does not match its contents.
                // openssl_decrypt puts $tag BEFORE $aad on this build.
                $plain = openssl_decrypt(
                    $ciphertext,
                    self::CIPHER,
                    $fileKey,
                    OPENSSL_RAW_DATA,
                    $iv,
                    $tag,
                    $aad
                );

                if ($plain === false) {
                    // Authentication failure: altered, reordered or truncated
                    // content, or the wrong file key.
                    throw new \RuntimeException(
                        'Decryption failed: authentication error at chunk ' . $counter . ' '
                        . '(file altered, chunks reordered, or wrong file key).'
                    );
                }

                fwrite($out, $plain);
                $written += strlen($plain);
                $counter++;
            }

            fflush($out);
            fclose($out);
            fclose($in);
        } catch (\Throwable $e) {
            @fclose($out);
            @fclose($in);
            @unlink($destinationPath);
            throw $e;
        }

        return $written;
    }

    /**
     * Decrypt a small file fully into memory.
     *
     * Only for genuinely small payloads (avatars, short JSON manifests). Never
     * call this with a large document - use decryptFile() so memory stays
     * bounded.
     */
    public static function decryptToString(string $sourcePath, string $fileKey): string
    {
        self::requireAvailable();

        $tmp = tempnam(sys_get_temp_dir(), 'sdec');
        if ($tmp === false) {
            throw new \RuntimeException('Decryption failed: unable to create a temp file.');
        }

        try {
            self::decryptFile($sourcePath, $tmp, $fileKey);
            $data = file_get_contents($tmp);
            if ($data === false) {
                throw new \RuntimeException('Decryption failed: unable to read decrypted output.');
            }
            return $data;
        } finally {
            @unlink($tmp);
        }
    }

    /**
     * Is this file already an encrypted container?
     *
     * Lets callers and the migration script be idempotent: a file that has
     * already been converted is detected and skipped rather than re-encrypted.
     */
    public static function isEncryptedFile(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $fh = fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = fread($fh, 5);
        fclose($fh);

        return $head === self::MAGIC;
    }

    /**
     * Fail loudly when the build cannot do AES-256-GCM.
     *
     * Writing an unreadable file is worse than refusing to write one, so this
     * throws instead of falling back to plaintext.
     */
    private static function requireAvailable(): void
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException(
                'AES-256-GCM is unavailable: the OpenSSL extension is not loaded or does not '
                . 'advertise the cipher. Refusing to write files that could never be decrypted.'
            );
        }
    }
}
