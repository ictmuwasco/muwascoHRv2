<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Helpers\StorageEncryption;
use PHPUnit\Framework\TestCase;

/**
 * Proves the STAGING contract that encrypt_existing_files.php depends on.
 *
 * The defect this suite exists to prevent: the migration script used to call
 * encryptFile(), which renames the ciphertext over the original IMMEDIATELY.
 * It then verified the round trip - so by the time a mismatch was discovered,
 * the plaintext was already gone and the file was undecryptable. The failure
 * message even claimed "plaintext retained", which was simply false.
 *
 * The safety property is therefore: encryptFileStaged() must not modify the
 * source in any way, and the plaintext must still be readable and byte-identical
 * after any subsequent failure.
 */
final class StagedEncryptionTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        if (!StorageEncryption::isAvailable()) {
            $this->markTestSkipped('This PHP build has no AES-256-GCM.');
        }

        $this->dir = sys_get_temp_dir() . '/stagedtest_' . bin2hex(random_bytes(6));
        if (!mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            $this->markTestSkipped('Could not create a temp directory.');
        }

        // A master key is required for wrapKey(). A throwaway one, so this
        // suite can never touch real data even if the environment leaks in.
        if (getenv('STORAGE_ENCRYPTION_KEY') === false) {
            putenv('STORAGE_ENCRYPTION_KEY=' . base64_encode(random_bytes(32)));
        }
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function writePlaintext(string $name, string $contents): string
    {
        $path = $this->dir . '/' . $name;
        file_put_contents($path, $contents);
        return $path;
    }

    /** A body big enough to span several 256 KiB chunks. */
    private function bigPayload(): string
    {
        $chunk = str_repeat('SECRET-DOCUMENT-CONTENT-', 12000);
        return $chunk . $chunk;
    }

    /** Unwrap a file key, as the migration script does. */
    private function fileKeyFor(array $meta, string $table, int $id): string
    {
        return StorageEncryption::unwrapKey(
            $meta['wrapped'],
            $meta['nonce'],
            StorageEncryption::keyAad($table, $id),
            $meta['key_version']
        );
    }

    // =================================================================
    // The property the migration script relies on
    // =================================================================

    public function testStagedEncryptionLeavesTheSourceFileByteIdentical(): void
    {
        $contents = 'National ID document contents';
        $path     = $this->writePlaintext('doc.pdf', $contents);
        $before   = hash_file('sha256', $path);
        $size     = filesize($path);

        $meta = StorageEncryption::encryptFileStaged($path, 'employee_documents', 1);

        // The source must be untouched: same bytes, same size, not a container.
        $this->assertSame($before, hash_file('sha256', $path), 'source was modified');
        $this->assertSame($size, filesize($path), 'source size changed');
        $this->assertFalse(
            StorageEncryption::isEncryptedFile($path),
            'source must still be plaintext, not a container'
        );

        // The ciphertext lives beside it, separately.
        $this->assertNotSame($path, $meta['staged_path']);
        $this->assertFileExists($meta['staged_path']);
        $this->assertTrue(StorageEncryption::isEncryptedFile($meta['staged_path']));

        StorageEncryption::discardStagedFile($meta['staged_path']);
    }

    public function testPlaintextSurvivesAVerificationFailure(): void
    {
        $contents = 'DIAGNOSIS: this content must never be lost';
        $path     = $this->writePlaintext('scan.pdf', $contents);
        $before   = hash_file('sha256', $path);

        $meta = StorageEncryption::encryptFileStaged($path, 'employee_documents', 2);

        // Simulate the failure the migration script must survive: the key row
        // write blows up AFTER encryption but BEFORE the commit.
        try {
            throw new \RuntimeException('could not record the key: simulated DB failure');
        } catch (\Throwable $e) {
            // This is the migration script's catch block.
            StorageEncryption::discardStagedFile($meta['staged_path']);
        }

        // The plaintext is still there, unchanged, and still readable.
        $this->assertFileExists($path, 'plaintext was destroyed by a failure');
        $this->assertSame($before, hash_file('sha256', $path), 'plaintext was corrupted');
        $this->assertSame($contents, file_get_contents($path));
        $this->assertFileDoesNotExist($meta['staged_path'], 'staging file leaked');
    }

    public function testCommitReplacesThePlaintextAtomically(): void
    {
        $contents = 'Payslip 2026-01';
        $path     = $this->writePlaintext('payslip.pdf', $contents);

        $meta = StorageEncryption::encryptFileStaged($path, 'employee_documents', 3);

        // Verify the staged ciphertext reads back correctly, exactly as the
        // migration script does before committing.
        $fileKey   = $this->fileKeyFor($meta, 'employee_documents', 3);
        $roundTrip = $this->dir . '/roundtrip.tmp';
        StorageEncryption::decryptFile($meta['staged_path'], $roundTrip, $fileKey);
        $this->assertSame($contents, file_get_contents($roundTrip));
        @unlink($roundTrip);

        // Only now: commit.
        StorageEncryption::commitStagedFile($meta['staged_path'], $path);

        $this->assertTrue(StorageEncryption::isEncryptedFile($path), 'not committed');
        $this->assertFileDoesNotExist($meta['staged_path'], 'staging file should be gone');
    }

    public function testCommitFailureKeepsBothFiles(): void
    {
        $path = $this->writePlaintext('keep.txt', 'original bytes');
        $meta = StorageEncryption::encryptFileStaged($path, 'employee_documents', 4);

        // Committing into a directory that does not exist cannot succeed, and
        // must not destroy either the plaintext or the verified ciphertext.
        try {
            StorageEncryption::commitStagedFile($meta['staged_path'], $this->dir . '/nope/deep/f.bin');
            $this->fail('commit into a missing directory should fail');
        } catch (\RuntimeException $e) {
            $this->assertSame('original bytes', file_get_contents($path), 'plaintext lost');
            $this->assertFileExists($meta['staged_path'], 'staging file must be kept for recovery');
        }
    }

    // =================================================================
    // Round-trip integrity, including multi-chunk files
    // =================================================================

    public function testMultiChunkRoundTripReproducesEveryByte(): void
    {
        $contents = $this->bigPayload();
        $path     = $this->writePlaintext('large.pdf', $contents);

        $meta = StorageEncryption::encryptFileStaged($path, 'employee_documents', 5);
        $this->assertGreaterThan(
            StorageEncryption::DEFAULT_CHUNK_SIZE,
            $meta['bytes'],
            'payload should span more than one chunk'
        );

        $fileKey = $this->fileKeyFor($meta, 'employee_documents', 5);
        $out     = $this->dir . '/large.out';
        StorageEncryption::decryptFile($meta['staged_path'], $out, $fileKey);

        $this->assertSame(strlen($contents), $meta['bytes']);
        $this->assertSame($contents, file_get_contents($out), 'multi-chunk round trip lost data');
        $this->assertSame($meta['sha256'], hash_file('sha256', $out));

        @unlink($out);
        StorageEncryption::discardStagedFile($meta['staged_path']);
    }

    public function testInPlaceEncryptFileStillWorksForNewUploads(): void
    {
        $contents = 'fresh upload, no plaintext to protect';
        $path     = $this->writePlaintext('fresh.bin', $contents);

        // encryptFile() keeps its original signature and behaviour: it stages
        // then commits immediately, and returns metadata WITHOUT staged_path.
        $meta = StorageEncryption::encryptFile($path, 'employee_documents', 6);

        $this->assertArrayNotHasKey('staged_path', $meta);
        $this->assertTrue(StorageEncryption::isEncryptedFile($path));

        $fileKey = $this->fileKeyFor($meta, 'employee_documents', 6);
        $this->assertSame($contents, StorageEncryption::decryptToString($path, $fileKey));
    }

    public function testEmptyFileRoundTrips(): void
    {
        $path = $this->writePlaintext('empty.txt', '');

        $meta = StorageEncryption::encryptFileStaged($path, 'employee_documents', 7);
        StorageEncryption::commitStagedFile($meta['staged_path'], $path);

        $fileKey = $this->fileKeyFor($meta, 'employee_documents', 7);
        $this->assertSame('', StorageEncryption::decryptToString($path, $fileKey));
    }

    public function testCorruptedCiphertextIsRejectedNotSilentlyReturned(): void
    {
        $path = $this->writePlaintext('tamper.txt', 'authentic content here');
        $meta = StorageEncryption::encryptFileStaged($path, 'employee_documents', 8);
        StorageEncryption::commitStagedFile($meta['staged_path'], $path);

        // Flip a byte in the body, past the 18-byte header.
        $bytes = (string) file_get_contents($path);
        $bytes[30] = chr(ord($bytes[30]) ^ 0xFF);
        file_put_contents($path, $bytes);

        // GCM authentication must fail loudly rather than yield garbage.
        $this->expectException(\RuntimeException::class);
        StorageEncryption::decryptToString($path, $this->fileKeyFor($meta, 'employee_documents', 8));
    }
}
