<?php

declare(strict_types=1);

namespace App\Helpers;

/**
 * SealedStream - append-only AEAD log stream (PART C).
 *
 * StorageEncryption (PART B) seals a whole file into a chunked AES-256-GCM
 * container. That suits documents - written once, read many times - and is what
 * uploads/documents and policies use. It cannot serve a LOG: logs are appended
 * to on every request, so a chunked container would mean decrypting and
 * re-encrypting the whole file per line. A 310 MB laravel.log would cost
 * 310 MB of I/O on every log call, and isEncryptedFile() correctly REFUSES a
 * plaintext file, so sealing an actively-appended log was not possible at all.
 *
 * This is the missing third mode: encrypted at rest, still O(1) to append to,
 * decrypted exactly once on read. Full format and threat analysis in
 * backend/docs/security/SEALED_STREAM.md.
 *
 * FORMAT (version 1)
 *   Header, written once at creation:
 *     "MWSS1" 5 | version 1 | keyVersion 1 | wrappedLen 4 (uint32 BE) |
 *     keyNonce 12 | wrappedCiphertext||tag wrappedLen | streamId 16
 *   Then repeated to EOF:
 *     ciphertextLen 4 (uint32 BE) PREFIX | iv 12 | ciphertext||tag | same len TRAILER
 *
 *   The length is written BOTH before and after the record, deliberately.
 *   Appending needs the previous record's IV, and locating that record from EOF
 *   is the only O(1) way to get it - a forward walk would be O(file size) on
 *   every append, which is unacceptable for a hot log. The trailer makes the
 *   back-seek possible; the prefix makes the forward read possible. read()
 *   checks the two agree.
 *
 *   Deliberately NOT the MWSC1 container: the magic differs so a sealed stream
 *   can never be mistaken for a document, and StorageEncryption::decryptFile()
 *   rejects it rather than emitting MWSS1 bytes to a browser as a "PDF".
 *
 * TAMPER EVIDENCE
 *   Each record's AAD binds the PREVIOUS record's IV, not an integer counter.
 *   A counter would make every append O(file size) because the writer must
 *   count existing records; the chain link comes from the trailer in O(1).
 *   Swapping, deleting or replacing a middle record breaks the chain; moving
 *   one to another file fails because streamId is in the AAD.
 *   A TAIL truncation is deliberately NOT detected - a hash chain cannot see a
 *   removed suffix - which is an acceptable trade for append-only logs and is
 *   documented rather than papered over.
 *
 * KEYS
 *   master key (STORAGE_ENCRYPTION_KEY, env only)
 *     -> per-STREAM key (random 32B, wrapped once, stored in the header)
 *          -> every record. Only the WRAPPED key is persisted, so the at-rest
 *   guarantee matches PART B. Random 96-bit nonce per record; stream keys are
 *   never shared between streams, so cross-file collision cannot occur.
 *
 * CONCURRENCY
 *   append() holds LOCK_EX across read-header-then-write-record, so concurrent
 *   workers cannot interleave a partial header with a record. A reader meets a
 *   short trailing record (a writer killed mid-append) and reports it rather
 *   than throwing.
 *
 * SCOPE
 *   This does not protect a running server: the application holds the master
 *   key, so anything running as the application can read these files. The
 *   guarantee is AT REST - a copied folder, stolen backup or leaked archive is
 *   opaque without the key.
 */
final class SealedStream
{
    /** Format marker at the head of every sealed stream. */
    public const MAGIC = 'MWSS1';

    /** Format version. Bump only alongside a matching reader branch. */
    private const VERSION = "\x01";

    /** AES-256-GCM key length in bytes. */
    private const KEY_BYTES = 32;

    /** 96-bit GCM nonce, for both the key wrap and every record. */
    private const IV_BYTES = 12;

    /** GCM authentication tag appended to every ciphertext. */
    private const TAG_BYTES = 16;

    /** Random per-stream identifier length, in bytes. */
    private const STREAM_ID_BYTES = 16;

    /** Cipher, matching StorageEncryption. */
    private const CIPHER = 'aes-256-gcm';

    /** Fixed header bytes before wrappedLen/streamId: see the format table. */
    private const HEADER_PREFIX = 23;

    /**
     * Smallest possible FRAMED record on disk:
     *   4 length prefix + 12 IV + 16 GCM tag + 1 plaintext byte + 4 trailer.
     *
     * Used as a lower bound when back-seeking from EOF. The previous name
     * suggested it covered only the prefix, and the value (IV + 4 = 16) was far
     * below the real minimum, so the guard let a truncated file seek backwards
     * into the header and reinterpret key material as a record length.
     */
    private const RECORD_MIN = 4 + self::IV_BYTES + self::TAG_BYTES + 1 + 4;

    private function __construct()
    {
    }

    /**
     * Is the sealed-stream primitive usable in this PHP build?
     *
     * The same check StorageEncryption makes, exposed separately so a
     * deployment missing the cipher fails at boot rather than writing opaque
     * files.
     */
    public static function isAvailable(): bool
    {
        return extension_loaded('openssl')
            && in_array(self::CIPHER, openssl_get_cipher_methods(), true);
    }

    /**
     * Is this path already a sealed stream?
     *
     * The plaintext counterpart is `!is_file()`, which is what lets callers
     * migrate idempotently: a file already converted is skipped rather than
     * wrapped a second time.
     */
    public static function isSealed(string $path): bool
    {
        if (!is_file($path)) {
            return false;
        }
        $fh = @fopen($path, 'rb');
        if ($fh === false) {
            return false;
        }
        $head = fread($fh, 5);
        fclose($fh);

        return $head === self::MAGIC;
    }
    /**
     * Append plaintext to a sealed stream, creating it on first use.
     *
     * Creates the parent directory if needed, so a first log write on a fresh
     * deployment needs no separate mkdir step.
     *
     * @return bool false when the stream could not be written. The caller keeps
     *              running: logging must never break a request.
     */
    public static function append(string $path, string $plaintext): bool
    {
        if ($plaintext === '') {
            return true;
        }
        if (!self::isAvailable()) {
            return false;
        }

        $dir = dirname($path);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return false;
        }

        // 'c+b' creates when absent and never truncates when present, and is
        // required rather than 'a' because the header read below must seek.
        $fh = @fopen($path, 'c+b');
        if ($fh === false) {
            return false;
        }

        try {
            if (!flock($fh, LOCK_EX)) {
                return false;
            }

            $header = self::ensureHeader($fh, $path);
            if ($header === null) {
                return false;
            }

            $streamKey = self::unwrapStreamKey(
                $header['wrapped'],
                $header['key_nonce'],
                $header['key_version'],
                $header['stream_id']
            );

            $ciphertext = self::sealRecord(
                $plaintext,
                $streamKey,
                $header['stream_id'],
                self::lastRecordIv($fh, $header['length'])
            );
            if ($ciphertext === null) {
                return false;
            }

            // Length prefix, record, then an identical length trailer. The
            // trailer is what makes the NEXT append's O(1) back-seek possible.
            $framed = pack('N', strlen($ciphertext)) . $ciphertext . pack('N', strlen($ciphertext));

            fseek($fh, 0, SEEK_END);
            if (fwrite($fh, $framed) === false) {
                return false;
            }

            // Flush before releasing the lock: flock() is advisory, and a second
            // worker must not observe a header we have not actually written.
            fflush($fh);

            return true;
        } catch (\Throwable) {
            // A broken stream must never take the request down with it.
            return false;
        } finally {
            @flock($fh, LOCK_UN);
            @fclose($fh);
        }
    }

    /**
     * Decrypt a whole sealed stream to a string.
     *
     * Fine for logs, which are bounded by retention. Documents go through
     * StorageEncryption::decryptFile(), which streams to disk instead.
     *
     * @return array{records:int, bytes:int, truncated:bool, plaintext:string}
     *         `truncated` is true when the file ends mid-record, which is the
     *         expected shape after a writer is killed mid-append.
     */
    public static function read(string $path): array
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('AES-256-GCM is unavailable; cannot read a sealed stream.');
        }
        if (!is_file($path)) {
            throw new \RuntimeException('No such sealed stream: ' . basename($path));
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read sealed stream: ' . basename($path));
        }

        $header    = self::parseHeader($raw, $path);
        $streamKey = self::unwrapStreamKey(
            $header['wrapped'],
            $header['key_nonce'],
            $header['key_version'],
            $header['stream_id']
        );

        $offset  = $header['length'];
        $total   = strlen($raw);
        $records = 0;
        $bytes   = 0;
        $out     = '';
        $prevIv  = '';

        while ($offset < $total) {
            if ($offset + 4 > $total) {
                return self::streamResult($records, $bytes, true, $out);
            }
            $len = unpack('N', substr($raw, $offset, 4))[1];

            // Torn tail: the writer died mid-append. Guard BEFORE touching the
            // record so a short read can never be mistaken for a short but
            // valid ciphertext. Everything before this point is authentic and is
            // returned rather than discarded.
            if ($len < self::IV_BYTES + self::TAG_BYTES + 1
                || $offset + 4 + $len + 4 > $total
            ) {
                return self::streamResult($records, $bytes, true, $out);
            }

            $record = substr($raw, $offset + 4, $len);
            $iv     = substr($record, 0, self::IV_BYTES);
            $body   = substr($record, self::IV_BYTES);

            // The trailer must repeat the prefix exactly. It is not
            // authenticated (it is outside the AEAD), so it is used only as a
            // structural check; the GCM tag is what actually proves integrity.
            $trailer = unpack('N', substr($raw, $offset + 4 + $len, 4))[1];
            if ($trailer !== $len) {
                return self::streamResult($records, $bytes, true, $out);
            }

            $plain = self::openRecord($body, $iv, $streamKey, $header['stream_id'], $prevIv);
            if ($plain === null) {
                throw new \RuntimeException(
                    'Sealed stream authentication failed at record ' . $records
                    . ' (wrong STORAGE_ENCRYPTION_KEY, or the file was altered). '
                    . 'File: ' . basename($path)
                );
            }

            $out    .= $plain;
            $bytes  += strlen($plain);
            $records++;
            $prevIv  = $iv;
            $offset += 4 + $len + 4;
        }

        return self::streamResult($records, $bytes, false, $out);
    }

    /**
     * Read a sealed stream, or return a plaintext file unchanged.
     *
     * scripts/storage/seal_storage.php leaves the ORIGINAL in place and writes
     * the sealed copy beside it, so a reader that meets either state must
     * behave sensibly rather than throw.
     */
    public static function readOrPlaintext(string $path): array
    {
        if (self::isSealed($path)) {
            return self::read($path);
        }

        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw new \RuntimeException('Unable to read file: ' . basename($path));
        }

        return self::streamResult(0, strlen($raw), false, $raw);
    }
    /**
     * Write a whole plaintext buffer as a NEW sealed stream, atomically.
     *
     * For files that are sealed once and only read afterwards: log archives,
     * database dumps, one-off snapshots. The source is never modified here - the
     * caller decides whether to delete it after verifying the round trip.
     *
     * One record per line keeps `tail` and other line-oriented tooling
     * meaningful, and caps how much ciphertext a single pathological line can
     * force the reader to hold.
     *
     * @return array{stream_id:string, key_version:int, records:int, bytes:int, sha256:string}
     */
    public static function sealBuffer(string $destination, string $plaintext): array
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('AES-256-GCM is unavailable; refusing to seal.');
        }

        $dir = dirname($destination);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create directory: ' . $dir);
        }

        $streamId = random_bytes(self::STREAM_ID_BYTES);
        $key      = random_bytes(self::KEY_BYTES);
        $version  = StorageEncryption::currentKeyVersion();
        $header   = self::buildHeader($streamId, $key, $version);

        $body   = '';
        $prev   = '';
        $count  = 0;
        $lines  = $plaintext === '' ? [] : explode("\n", $plaintext);

        // explode() on text ending in "\n" yields a trailing empty element.
        // Re-adding the separator to that would append a blank line the source
        // never had, so drop it and remember the source already ended in a
        // newline (which is true for every log file).
        $endsWithNewline = $plaintext !== '' && substr($plaintext, -1) === "\n";
        if ($endsWithNewline) {
            array_pop($lines);
        }

        foreach ($lines as $line) {
            $record = self::sealRecord($line . "\n", $key, $streamId, $prev);
            if ($record === null) {
                throw new \RuntimeException('Failed to seal a record.');
            }
            $body   .= pack('N', strlen($record)) . $record . pack('N', strlen($record));
            $prev    = substr($record, 0, self::IV_BYTES);
            $count++;
        }

        $tmp = $destination . '.seal-' . getmypid() . '.tmp';
        if (@file_put_contents($tmp, $header . $body, LOCK_EX) === false) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to write the sealed stream to disk.');
        }

        // rename() within one directory is atomic on both NTFS and ext4, so a
        // concurrent reader sees either no file or the complete sealed file -
        // never a half-written one.
        if (!@rename($tmp, $destination)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to commit the sealed stream.');
        }

        return [
            'stream_id'   => bin2hex($streamId),
            'key_version' => $version,
            'records'     => $count,
            'bytes'       => strlen($plaintext),
            'sha256'      => hash('sha256', $plaintext),
        ];
    }

    // -----------------------------------------------------------------------
    // Streaming (memory-bounded) seal and open
    //
    // sealBuffer() and read() both hold the entire plaintext in a PHP string.
    // That is fine for a 300 KB daily log and fatal for laravel.log at 310 MB:
    // PHP would need the source, the exploded line array AND the accumulated
    // ciphertext body all resident at once, which is well over 1 GB. These two
    // work a line at a time so peak memory is one line, not one file.
    // -----------------------------------------------------------------------

    /**
     * Seal a file line by line into a NEW stream, atomically, with bounded memory.
     *
     * Byte-for-byte equivalent to sealBuffer(file_get_contents($src)) but
     * without materialising the file. This is the migration path for large
     * archives; the source is never modified - the caller removes it only
     * after comparing the reported sha256 against its own.
     *
     * Records mirror the source lines exactly, including a final line with no
     * trailing newline, so the round trip is lossless rather than
     * newline-normalised.
     *
     * @return array{stream_id:string, key_version:int, records:int, bytes:int, sha256:string}
     */
    public static function sealStream(string $sourcePath, string $destination, ?callable $onRecord = null): array
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('AES-256-GCM is unavailable; refusing to seal.');
        }
        if (!is_file($sourcePath)) {
            throw new \RuntimeException('No such file: ' . $sourcePath);
        }

        $dir = dirname($destination);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Unable to create directory: ' . $dir);
        }

        $src = @fopen($sourcePath, 'rb');
        if ($src === false) {
            throw new \RuntimeException('Unable to read source: ' . basename($sourcePath));
        }

        $streamId = random_bytes(self::STREAM_ID_BYTES);
        $key      = random_bytes(self::KEY_BYTES);
        $version  = StorageEncryption::currentKeyVersion();

        $tmp = $destination . '.seal-' . getmypid() . '.tmp';
        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            fclose($src);
            throw new \RuntimeException('Unable to write the sealed stream to disk.');
        }

        $hash  = hash_init('sha256');
        $prev  = '';
        $count = 0;
        $bytes = 0;

        try {
            fwrite($out, self::buildHeader($streamId, $key, $version));

            while (($line = fgets($src)) !== false) {
                // fgets() keeps the "\n" when present and omits it only on the
                // final line of a file that does not end in one. Sealing the line
                // verbatim preserves both cases exactly.
                $record = self::sealRecord($line, $key, $streamId, $prev);
                if ($record === null) {
                    throw new \RuntimeException('Failed to seal a record.');
                }

                fwrite($out, pack('N', strlen($record)) . $record . pack('N', strlen($record)));
                $prev   = substr($record, 0, self::IV_BYTES);
                $count++;
                $bytes += strlen($line);
                hash_update($hash, $line);

                if ($onRecord !== null && ($count % 20000) === 0) {
                    $onRecord($count, $bytes);
                }
            }

            fflush($out);
        } catch (\Throwable $e) {
            fclose($src);
            fclose($out);
            @unlink($tmp);
            throw $e;
        }

        fclose($src);
        fclose($out);

        if (!@rename($tmp, $destination)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to commit the sealed stream.');
        }

        return [
            'stream_id'   => bin2hex($streamId),
            'key_version' => $version,
            'records'     => $count,
            'bytes'       => $bytes,
            'sha256'      => hash_final($hash),
        ];
    }
    // __STREAM2__

    /**
     * Decrypt a stream to a file on disk, one record at a time.
     *
     * The memory-bounded counterpart to read(), for recovering a 310 MB log.
     * Every record's tag is verified while streaming; a failure part-way
     * through deletes the partial output rather than leaving a truncated file
     * that looks like a successful restore.
     *
     * @return array{stream_id:string, key_version:int, records:int, bytes:int, truncated:bool, sha256:string}
     */
    public static function readToFile(string $sourcePath, string $destination): array
    {
        if (!self::isAvailable()) {
            throw new \RuntimeException('AES-256-GCM is unavailable; cannot read a sealed stream.');
        }

        $head = @file_get_contents($sourcePath, false, null, 0, 4096);
        if ($head === false) {
            throw new \RuntimeException('Unable to read sealed stream: ' . basename($sourcePath));
        }

        $header = self::parseHeader($head, $sourcePath);
        $key    = self::unwrapStreamKey(
            $header['wrapped'],
            $header['key_nonce'],
            $header['key_version'],
            $header['stream_id']
        );

        $fh = @fopen($sourcePath, 'rb');
        if ($fh === false) {
            throw new \RuntimeException('Unable to open sealed stream: ' . basename($sourcePath));
        }

        $dir = dirname($destination);
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            fclose($fh);
            throw new \RuntimeException('Unable to create directory: ' . $dir);
        }

        $tmp = $destination . '.open-' . getmypid() . '.tmp';
        $out = @fopen($tmp, 'wb');
        if ($out === false) {
            fclose($fh);
            throw new \RuntimeException('Unable to write recovered file.');
        }

        $hash      = hash_init('sha256');
        $prev      = '';
        $count     = 0;
        $bytes     = 0;
        $truncated = false;

        try {
            fseek($fh, $header['length'], SEEK_SET);

            while (true) {
                $prefix = fread($fh, 4);
                if ($prefix === false || $prefix === '') {
                    break; // clean EOF
                }
                if (strlen($prefix) < 4) {
                    $truncated = true;
                    break;
                }

                $len = unpack('N', $prefix)[1];
                if ($len < self::IV_BYTES + self::TAG_BYTES + 1) {
                    $truncated = true;
                    break;
                }

                $body = fread($fh, $len);
                if ($body === false || strlen($body) < $len) {
                    $truncated = true; // torn tail
                    break;
                }

                $trailer = fread($fh, 4);
                if ($trailer === false || strlen($trailer) < 4) {
                    $truncated = true;
                    break;
                }
                if (unpack('N', $trailer)[1] !== $len) {
                    $truncated = true;
                    break;
                }

                $iv    = substr($body, 0, self::IV_BYTES);
                $plain = self::openRecord(substr($body, self::IV_BYTES), $iv, $key, $header['stream_id'], $prev);
                if ($plain === null) {
                    throw new \RuntimeException(
                        'Sealed stream authentication failed at record ' . $count
                        . ' (wrong STORAGE_ENCRYPTION_KEY, or the file was altered). '
                        . 'File: ' . basename($sourcePath)
                    );
                }

                fwrite($out, $plain);
                hash_update($hash, $plain);
                $bytes += strlen($plain);
                $count++;
                $prev   = $iv;
            }

            fflush($out);
        } catch (\Throwable $e) {
            fclose($fh);
            fclose($out);
            @unlink($tmp);
            throw $e;
        }

        fclose($fh);
        fclose($out);

        if (!@rename($tmp, $destination)) {
            @unlink($tmp);
            throw new \RuntimeException('Unable to commit the recovered file.');
        }

        return [
            'stream_id'   => bin2hex($header['stream_id']),
            'key_version' => $header['key_version'],
            'records'     => $count,
            'bytes'       => $bytes,
            'truncated'   => $truncated,
            'sha256'      => hash_final($hash),
        ];
    }


    /**
     * Wrap the stream key and serialise the header.
     *
     * Shared by ensureHeader() (append path) and sealBuffer() (one-shot path) so
     * the two can never produce different headers.
     */
    private static function buildHeader(string $streamId, string $streamKey, int $version): string
    {
        $keyNonce = random_bytes(self::IV_BYTES);

        $tag = null;
        // openssl_encrypt puts $tag then $aad then $tag_length (see CRYPTO_NOTES).
        $wrapped = openssl_encrypt(
            $streamKey,
            self::CIPHER,
            StorageEncryption::masterKey($version),
            OPENSSL_RAW_DATA,
            $keyNonce,
            $tag,
            self::keyAad($streamId, $version),
            self::TAG_BYTES
        );

        if ($wrapped === false || $tag === null) {
            throw new \RuntimeException('Failed to wrap the stream key.');
        }
        $wrapped .= $tag;

        return self::MAGIC . self::VERSION . chr($version)
            . pack('N', strlen($wrapped)) . $keyNonce . $wrapped . $streamId;
    }
    /**
     * Ensure the handle carries a header, then parse and return it.
     *
     * Called with LOCK_EX held, so the emptiness check and the write are atomic
     * with respect to other appenders.
     *
     * @return array{stream_id:string,key_nonce:string,key_version:int,wrapped:string,length:int}|null
     */
    private static function ensureHeader($fh, string $path): ?array
    {
        $size = (int) fstat($fh)['size'];

        if ($size > 0) {
            // Read ONLY the header bytes, never the whole stream.
            //
            // The obvious version of this is readAt($fh, 0, $size), which was
            // here and made every append O(file size): appending one line to
            // laravel.log (310 MB) read 310 MB, on every request that logs.
            // Two bounded reads instead - the fixed prefix carries wrappedLen,
            // which is all that is needed to know how much more to read.
            $prefix = self::readAt($fh, 0, self::HEADER_PREFIX);
            if (strlen($prefix) < self::HEADER_PREFIX) {
                throw new \RuntimeException('Truncated sealed stream header: ' . basename($path));
            }

            $wrappedLen = unpack('N', substr($prefix, 7, 4))[1];
            $headerLen  = self::HEADER_PREFIX + $wrappedLen + self::STREAM_ID_BYTES;

            // wrappedLen is attacker/corruption-controlled, so bound it before
            // using it as a read length or a huge value seeks past EOF.
            if ($wrappedLen < 1 || $wrappedLen > 4096 || $size < $headerLen) {
                throw new \RuntimeException('Truncated sealed stream header: ' . basename($path));
            }

            return self::parseHeader(self::readAt($fh, 0, $headerLen), $path);
        }

        rewind($fh);
        $header = self::buildHeader(
            random_bytes(self::STREAM_ID_BYTES),
            random_bytes(self::KEY_BYTES),
            StorageEncryption::currentKeyVersion()
        );

        if (fwrite($fh, $header) === false) {
            return null;
        }
        fflush($fh);

        return self::parseHeader($header, $path);
    }

    /**
     * Parse a sealed stream's header.
     *
     * @return array{stream_id:string,key_nonce:string,key_version:int,wrapped:string,length:int}
     */
    private static function parseHeader(string $raw, string $path): array
    {
        if (strncmp($raw, self::MAGIC, 5) !== 0) {
            throw new \RuntimeException('Not a sealed stream: ' . basename($path));
        }
        if (strlen($raw) < self::HEADER_PREFIX + 1) {
            throw new \RuntimeException('Truncated sealed stream header: ' . basename($path));
        }
        if ($raw[5] !== self::VERSION) {
            throw new \RuntimeException(
                'Unsupported sealed stream version ' . ord($raw[5]) . ': ' . basename($path)
            );
        }

        $version    = ord($raw[6]);
        $wrappedLen = unpack('N', substr($raw, 7, 4))[1];
        $keyNonce   = substr($raw, 11, self::IV_BYTES);
        $streamIdAt = self::HEADER_PREFIX + $wrappedLen;

        if ($streamIdAt + self::STREAM_ID_BYTES > strlen($raw)) {
            throw new \RuntimeException('Truncated sealed stream header: ' . basename($path));
        }

        return [
            'key_nonce'   => $keyNonce,
            'key_version' => max(1, $version),
            'wrapped'     => substr($raw, self::HEADER_PREFIX, $wrappedLen),
            'stream_id'   => substr($raw, $streamIdAt, self::STREAM_ID_BYTES),
            'length'      => $streamIdAt + self::STREAM_ID_BYTES,
        ];
    }

    /**
     * IV of the final record, or '' when the stream has no records yet.
     *
     * O(1): the trailing 4 bytes are the last record's total length
     * (iv || ciphertext || tag), and the IV is the first 12 of those bytes.
     *
     * @param int $headerLength Bytes consumed by the header, from parseHeader().
     */
    private static function lastRecordIv($fh, int $headerLength): string
    {
        $size = (int) fstat($fh)['size'];
        if ($size < $headerLength + self::RECORD_MIN) {
            return '';
        }

        // The trailing 4 bytes are the final record's length TRAILER, so that
        // record begins at $size - 4 - $len and its IV is the first IV_BYTES of
        // it. Reading the trailer is what keeps the back-seek O(1).
        $len = unpack('N', self::readAt($fh, $size - 4, 4))[1];
        if ($len < self::IV_BYTES + self::TAG_BYTES + 1) {
            return '';
        }

        $start = $size - 4 - $len;
        if ($start < $headerLength) {
            return '';
        }

        $iv = self::readAt($fh, $start, self::IV_BYTES);

        return strlen($iv) === self::IV_BYTES ? $iv : '';
    }

    /** Positional read that leaves the handle position caller-defined. */
    private static function readAt($fh, int $offset, int $length): string
    {
        if ($length <= 0 || fseek($fh, $offset, SEEK_SET) !== 0) {
            return '';
        }
        $data = fread($fh, $length);

        return $data === false ? '' : $data;
    }
    // -----------------------------------------------------------------------
    // Record sealing / opening
    // -----------------------------------------------------------------------

    /** AAD binding the wrapped stream key to its stream and key version. */
    private static function keyAad(string $streamId, int $keyVersion): string
    {
        return self::MAGIC . "\x00" . $streamId . "\x00" . chr($keyVersion);
    }

    /**
     * AAD binding a record to its place in the chain.
     *
     * $previousIv is '' for the first record, so its AAD is distinguishable from
     * every later record's - an attacker cannot forge record 0 by copying a
     * later record's AAD.
     */
    private static function recordAad(string $streamId, string $previousIv): string
    {
        return self::MAGIC . "\x00" . $streamId . "\x00" . $previousIv;
    }

    /** Encrypt one record, returning iv || ciphertext || tag. */
    private static function sealRecord(
        string $plaintext,
        string $streamKey,
        string $streamId,
        string $previousIv
    ): ?string {
        $iv  = random_bytes(self::IV_BYTES);
        $tag = null;

        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $streamKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::recordAad($streamId, $previousIv),
            self::TAG_BYTES
        );

        if ($ciphertext === false || $tag === null) {
            return null;
        }

        return $iv . $ciphertext . $tag;
    }

    /** Decrypt one record (ciphertext || tag), verifying the chain link. */
    private static function openRecord(
        string $ciphertext,
        string $iv,
        string $streamKey,
        string $streamId,
        string $previousIv
    ): ?string {
        if (strlen($ciphertext) <= self::TAG_BYTES) {
            return null;
        }
        $body = substr($ciphertext, 0, -self::TAG_BYTES);
        $tag  = substr($ciphertext, -self::TAG_BYTES);

        // openssl_decrypt puts $tag BEFORE $aad (see CRYPTO_NOTES §1).
        $plain = openssl_decrypt(
            $body,
            self::CIPHER,
            $streamKey,
            OPENSSL_RAW_DATA,
            $iv,
            $tag,
            self::recordAad($streamId, $previousIv)
        );

        return $plain === false ? null : $plain;
    }

    /**
     * Recover the stream key by unwrapping the header blob.
     *
     * @throws \RuntimeException on authentication failure.
     */
    private static function unwrapStreamKey(
        string $wrapped,
        string $keyNonce,
        int $keyVersion,
        string $streamId
    ): string {
        if (strlen($wrapped) <= self::TAG_BYTES || strlen($keyNonce) !== self::IV_BYTES) {
            throw new \RuntimeException('Corrupt sealed stream header.');
        }

        $key = openssl_decrypt(
            substr($wrapped, 0, -self::TAG_BYTES),
            self::CIPHER,
            StorageEncryption::masterKey($keyVersion),
            OPENSSL_RAW_DATA,
            $keyNonce,
            substr($wrapped, -self::TAG_BYTES),
            self::keyAad($streamId, $keyVersion)
        );

        if ($key === false || strlen($key) !== self::KEY_BYTES) {
            throw new \RuntimeException(
                'Unable to unwrap the stream key: authentication failed '
                . '(wrong STORAGE_ENCRYPTION_KEY, or the file was altered).'
            );
        }

        return $key;
    }

    /** @return array{records:int, bytes:int, truncated:bool, plaintext:string} */
    private static function streamResult(int $records, int $bytes, bool $truncated, string $out): array
    {
        return [
            'records'   => $records,
            'bytes'     => $bytes,
            'truncated' => $truncated,
            'plaintext' => $out,
        ];
    }
}