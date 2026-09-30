<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Helpers\StorageEncryption;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the Part B at-rest encryption primitive.
 *
 * These are pure filesystem/crypto tests: no database, no application
 * bootstrap, no live HR data. Each test writes into a per-test temp directory
 * that is removed in tearDown.
 *
 * The behaviour pinned here is the security contract:
 *   - a file survives a full encrypt/decrypt round trip byte-for-byte
 *   - tampered ciphertext is REJECTED, never silently returned as garbage
 *   - a wrapped key cannot be moved between rows (AAD binding)
 *   - the wrong master key cannot open anything
 *   - a non-encrypted file is refused rather than returned as-is
 */
final class StorageEncryptionTest extends TestCase
{
    private string $dir;
    private string $masterKey;

    protected function setUp(): void
    {
        $this->masterKey = bin2hex(random_bytes(32));

        // env() reads $_ENV/$_SERVER/getenv; set all three so the helper sees
        // the same value regardless of which path it takes.
        $_ENV['STORAGE_ENCRYPTION_KEY'] = $this->masterKey;
        $_SERVER['STORAGE_ENCRYPTION_KEY'] = $this->masterKey;
        putenv('STORAGE_ENCRYPTION_KEY=' . $this->masterKey);

        unset($_ENV['STORAGE_ENCRYPTION_KEY_PREVIOUS'], $_SERVER['STORAGE_ENCRYPTION_KEY_PREVIOUS']);
        putenv('STORAGE_ENCRYPTION_KEY_PREVIOUS');

        $_ENV['STORAGE_ENCRYPTION_KEY_VERSION'] = '1';
        $_SERVER['STORAGE_ENCRYPTION_KEY_VERSION'] = '1';
        putenv('STORAGE_ENCRYPTION_KEY_VERSION=1');

        $this->dir = sys_get_temp_dir() . '/stor_enc_test_' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        unset(
            $_ENV['STORAGE_ENCRYPTION_KEY'],
            $_SERVER['STORAGE_ENCRYPTION_KEY'],
            $_ENV['STORAGE_ENCRYPTION_KEY_PREVIOUS'],
            $_SERVER['STORAGE_ENCRYPTION_KEY_PREVIOUS'],
            $_ENV['STORAGE_ENCRYPTION_KEY_VERSION'],
            $_SERVER['STORAGE_ENCRYPTION_KEY_VERSION']
        );
        putenv('STORAGE_ENCRYPTION_KEY');
        putenv('STORAGE_ENCRYPTION_KEY_PREVIOUS');
        putenv('STORAGE_ENCRYPTION_KEY_VERSION');
    }

    private function makeFile(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    /**
     * Encrypt, then unwrap and decrypt again, returning the output path.
     *
     * Shared by the round-trip tests so each one only states what is specific
     * to it (its content and its expectations).
     */
    private function roundTrip(string $path, string $table, int $id, ?int $chunkSize = null): string
    {
        $meta = StorageEncryption::encryptFile($path, $table, $id, $chunkSize);

        $fileKey = StorageEncryption::unwrapKey(
            $meta['wrapped'],
            $meta['nonce'],
            StorageEncryption::keyAad($table, $id),
            $meta['key_version']
        );

        $out = $this->dir . '/decrypted_' . basename($path);
        StorageEncryption::decryptFile($path, $out, $fileKey);

        return $out;
    }

    public function testCipherIsAvailableInThisBuild(): void
    {
        // The whole helper depends on this. If a host ever loses the cipher,
        // the refusal path (not silent plaintext) must engage.
        $this->assertTrue(
            StorageEncryption::isAvailable(),
            'AES-256-GCM must be available for PART B to work at all'
        );
    }

    public function testRoundTripPreservesContentExactly(): void
    {
        $plain = "Employee contract\n\0binary\x00bytes\xFF and unicode: Mwai\n";
        $path  = $this->makeFile('doc.pdf', $plain);

        // The file on disk must no longer contain the original bytes.
        $meta = StorageEncryption::encryptFile($path, 'employee_documents', 42);
        $onDisk = file_get_contents($path);
        $this->assertStringNotContainsString($plain, $onDisk, 'plaintext must not survive on disk');
        $this->assertStringStartsWith('MWSC1', $onDisk, 'container must carry the magic header');

        $out = $this->dir . '/out.pdf';
        $fileKey = StorageEncryption::unwrapKey(
            $meta['wrapped'],
            $meta['nonce'],
            StorageEncryption::keyAad('employee_documents', 42),
            $meta['key_version']
        );
        StorageEncryption::decryptFile($path, $out, $fileKey);

        $this->assertSame($plain, file_get_contents($out), 'round trip must be byte-exact');
    }

    public function testSha256MatchesAfterDecryption(): void
    {
        $plain = str_repeat('confidential payroll data ', 200);
        $path  = $this->makeFile('big.txt', $plain);

        // Encrypt ONCE, then reuse the returned metadata for both assertions.
        // roundTrip() encrypts again, so calling it here would hash a second,
        // differently-keyed container and the two digests could never match.
        $meta = StorageEncryption::encryptFile($path, 'employee_documents', 7);
        $this->assertSame(hash('sha256', $plain), $meta['sha256'], 'returned hash must match plaintext');

        $fileKey = StorageEncryption::unwrapKey(
            $meta['wrapped'],
            $meta['nonce'],
            StorageEncryption::keyAad('employee_documents', 7),
            $meta['key_version']
        );
        $out = $this->dir . '/sha.out';
        StorageEncryption::decryptFile($path, $out, $fileKey);

        $this->assertSame($meta['sha256'], hash_file('sha256', $out), 'decrypted file must re-hash identically');
    }

    public function testEmptyFileRoundTrips(): void
    {
        $path = $this->makeFile('empty.txt', '');
        $this->assertSame('', file_get_contents($this->roundTrip($path, 'employee_documents', 9)));
    }

    public function testMultiChunkFileRoundTrips(): void
    {
        // Force many chunks with a tiny chunk size, so the counter/IV logic is
        // exercised across chunk boundaries rather than on a single chunk.
        $plain = random_bytes(50_000);
        $path  = $this->makeFile('chunked.bin', $plain);

        $out = $this->roundTrip($path, 'leave_application_documents', 3, 1024);
        $this->assertSame($plain, file_get_contents($out));
    }

    public function testTamperedCiphertextIsRejected(): void
    {
        $plain = 'national id 12345678 and salary 250000';
        $path  = $this->makeFile('secret.txt', $plain);

        $meta = StorageEncryption::encryptFile($path, 'employee_documents', 11);
        $fileKey = StorageEncryption::unwrapKey(
            $meta['wrapped'],
            $meta['nonce'],
            StorageEncryption::keyAad('employee_documents', 11),
            $meta['key_version']
        );

        // Flip one bit in the body, after the 18-byte header.
        $raw = file_get_contents($path);
        $raw[40] = chr(ord($raw[40]) ^ 0x01);
        file_put_contents($path, $raw);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('authentication error');
        StorageEncryption::decryptFile($path, $this->dir . '/tampered.out', $fileKey);
    }

    public function testTruncatedFileIsRejected(): void
    {
        $path = $this->makeFile('trunc.txt', str_repeat('A', 4096));
        $meta = StorageEncryption::encryptFile($path, 'employee_documents', 12, 512);
        $fileKey = StorageEncryption::unwrapKey(
            $meta['wrapped'],
            $meta['nonce'],
            StorageEncryption::keyAad('employee_documents', 12),
            $meta['key_version']
        );

        // Chop the file mid-chunk.
        $raw = file_get_contents($path);
        file_put_contents($path, substr($raw, 0, (int) (strlen($raw) * 0.6)));

        $this->expectException(\RuntimeException::class);
        StorageEncryption::decryptFile($path, $this->dir . '/trunc.out', $fileKey);
    }

    public function testTamperedHeaderIsRejected(): void
    {
        // chunkSize is bound in as AAD, so editing it must fail authentication
        // rather than silently reinterpreting the chunk layout.
        $plain = str_repeat('B', 2000);
        $path  = $this->makeFile('hdr.txt', $plain);
        $meta  = StorageEncryption::encryptFile($path, 'employee_documents', 13, 1024);
        $fileKey = StorageEncryption::unwrapKey(
            $meta['wrapped'],
            $meta['nonce'],
            StorageEncryption::keyAad('employee_documents', 13),
            $meta['key_version']
        );

        // Header layout: MAGIC(0-4) + version(5) + baseNonce(6-13) +
        // chunkSize(14-17, big-endian). 1024 is 0x00000400, so byte 17 holds
        // the significant low byte; rewriting it changes the declared chunk
        // size and must fail the AAD check rather than silently reinterpreting
        // the chunk layout.
        $raw = file_get_contents($path);
        $raw[17] = "\x01";
        file_put_contents($path, $raw);

        $this->expectException(\RuntimeException::class);
        StorageEncryption::decryptFile($path, $this->dir . '/hdr.out', $fileKey);
    }

    public function testPlaintextFileIsRefusedNotReturnedAsIs(): void
    {
        // A file that was never encrypted must be refused explicitly, so a
        // migration script cannot mistake "not a container" for "success".
        $path = $this->makeFile('plain.pdf', '%PDF-1.4 never encrypted');
        $this->assertFalse(StorageEncryption::isEncryptedFile($path));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('bad magic');
        StorageEncryption::decryptFile($path, $this->dir . '/never.out', random_bytes(32));
    }

    public function testEncryptedFileIsDetected(): void
    {
        $path = $this->makeFile('detect.txt', 'hello');
        $this->assertFalse(StorageEncryption::isEncryptedFile($path));
        StorageEncryption::encryptFile($path, 'employee_documents', 14);
        $this->assertTrue(StorageEncryption::isEncryptedFile($path), 'second run must see the container');
    }

    public function testWrongMasterKeyCannotUnwrap(): void
    {
        $fileKey = StorageEncryption::generateFileKey();
        $wrapped = StorageEncryption::wrapKey($fileKey, 'employee_documents:1');

        // Simulate the operator losing/rotating the master key.
        $other = bin2hex(random_bytes(32));
        $_ENV['STORAGE_ENCRYPTION_KEY'] = $other;
        $_SERVER['STORAGE_ENCRYPTION_KEY'] = $other;
        putenv('STORAGE_ENCRYPTION_KEY=' . $other);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('authentication failed');
        StorageEncryption::unwrapKey($wrapped['wrapped'], $wrapped['nonce'], 'employee_documents:1', 1);
    }

    public function testShortMasterKeyIsRefused(): void
    {
        $_ENV['STORAGE_ENCRYPTION_KEY'] = 'too-short';
        $_SERVER['STORAGE_ENCRYPTION_KEY'] = 'too-short';
        putenv('STORAGE_ENCRYPTION_KEY=too-short');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('at least 32 characters');
        StorageEncryption::masterKey();
    }

    public function testMissingMasterKeyIsRefused(): void
    {
        unset($_ENV['STORAGE_ENCRYPTION_KEY'], $_SERVER['STORAGE_ENCRYPTION_KEY']);
        putenv('STORAGE_ENCRYPTION_KEY');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not set');
        StorageEncryption::masterKey();
    }

    public function testWrappedKeyCannotBeMovedToAnotherRow(): void
    {
        // The AAD binds table+id, so a wrapped key lifted off one document row
        // must not authenticate against a different one.
        $fileKey = StorageEncryption::generateFileKey();
        $wrapped = StorageEncryption::wrapKey($fileKey, StorageEncryption::keyAad('employee_documents', 1));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('authentication failed');
        StorageEncryption::unwrapKey(
            $wrapped['wrapped'],
            $wrapped['nonce'],
            StorageEncryption::keyAad('employee_documents', 2),
            1
        );
    }

    public function testRewrapPreservesTheFileKey(): void
    {
        // Rotation must not change the per-file key, otherwise every file
        // would have to be re-encrypted - the whole point of wrapping.
        $fileKey = StorageEncryption::generateFileKey();
        $aad     = StorageEncryption::keyAad('hr_policies', 5);

        $_ENV['STORAGE_ENCRYPTION_KEY_VERSION'] = '1';
        $_SERVER['STORAGE_ENCRYPTION_KEY_VERSION'] = '1';
        putenv('STORAGE_ENCRYPTION_KEY_VERSION=1');
        $atV1 = StorageEncryption::wrapKey($fileKey, $aad, 1);

        // Rotate: the new master key becomes version 2, and the old one is kept
        // as STORAGE_ENCRYPTION_KEY_PREVIOUS so version-1 rows stay readable -
        // that overlap is what makes a rotation non-destructive.
        $newKey = bin2hex(random_bytes(32));
        $_ENV['STORAGE_ENCRYPTION_KEY'] = $newKey;
        $_SERVER['STORAGE_ENCRYPTION_KEY'] = $newKey;
        putenv('STORAGE_ENCRYPTION_KEY=' . $newKey);
        $_ENV['STORAGE_ENCRYPTION_KEY_PREVIOUS'] = $this->masterKey;
        $_SERVER['STORAGE_ENCRYPTION_KEY_PREVIOUS'] = $this->masterKey;
        putenv('STORAGE_ENCRYPTION_KEY_PREVIOUS=' . $this->masterKey);
        $_ENV['STORAGE_ENCRYPTION_KEY_VERSION'] = '2';
        $_SERVER['STORAGE_ENCRYPTION_KEY_VERSION'] = '2';
        putenv('STORAGE_ENCRYPTION_KEY_VERSION=2');

        $atV2 = StorageEncryption::rewrapKey($atV1['wrapped'], $atV1['nonce'], $aad, 1, 2);

        $this->assertSame(2, $atV2['key_version']);
        $this->assertNotSame($atV1['wrapped'], $atV2['wrapped'], 're-wrap must produce a new blob');
        $this->assertSame(
            $fileKey,
            StorageEncryption::unwrapKey($atV2['wrapped'], $atV2['nonce'], $aad, 2),
            'the unwrapped file key must be unchanged by rotation'
        );
    }
}
