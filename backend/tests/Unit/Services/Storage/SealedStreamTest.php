<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Storage;

use App\Helpers\SealedStream;
use PHPUnit\Framework\TestCase;

/**
 * Contract tests for the append-only encrypted stream format (MWSS1).
 *
 * WHY THIS FORMAT EXISTS SEPARATELY FROM StorageEncryption
 *   StorageEncryption's MWSC1 container rewrites the whole file to append to it.
 *   That is correct for an immutable document and ruinous for a log: laravel.log
 *   is 310 MB here, so every line would cost 310 MB of reads and writes. A
 *   stream needs to be encrypted incrementally and authenticated incrementally.
 *
 * THE FAILURE THIS SUITE GUARDS AGAINST
 *   The record length is written BOTH as a prefix and as a trailer. That is not
 *   redundancy for its own sake: an append must learn the previous record's IV to
 *   continue the tamper chain, and the only O(1) way to find that record is to
 *   read its length from EOF and step backwards. An implementation that writes
 *   only the prefix silently loses O(1) appends; one that misreads the trailer
 *   corrupts the chain link and makes the SECOND append produce a file that
 *   cannot be read at all - which is the bug this file now covers.
 *
 * TAIL TRUNCATION IS NOT DETECTED, ON PURPOSE
 *   A hash chain cannot see a removed suffix. The tests assert the documented
 *   behaviour (torn tail is flagged, middle tampering throws) rather than
 *   pretending to a guarantee the format does not offer.
 */
final class SealedStreamTest extends TestCase
{
    private string $dir;
    private string $key;

    /** @var array<string,string|false> */
    private array $originalEnv = [];

    /**
     * Set the master key for this test.
     *
     * $_ENV MUST be updated alongside putenv(). The app's env() helper is
     *   $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key) ?: $default
     * and the test bootstrap runs Dotenv::createImmutable() first, so $_ENV is
     * already populated from the developer's real .env. putenv() alone would be
     * silently ignored and the test would keep decrypting with the .env key -
     * which is exactly how testWrongKeyIsRejected... came to pass a file it
     * should have rejected.
     */
    private function setMasterKey(string $raw): void
    {
        $b64 = base64_encode($raw);
        putenv("STORAGE_ENCRYPTION_KEY={$b64}");
        $_ENV['STORAGE_ENCRYPTION_KEY']     = $b64;
        $_SERVER['STORAGE_ENCRYPTION_KEY']  = $b64;
    }

    protected function setUp(): void
    {
        // A fixed key: these tests exercise the FORMAT, not key agreement.
        $this->key = str_repeat("\x11", 32);
        $this->originalEnv = [
            'STORAGE_ENCRYPTION_KEY'         => $_ENV['STORAGE_ENCRYPTION_KEY']     ?? false,
            'STORAGE_ENCRYPTION_KEY_VERSION' => $_ENV['STORAGE_ENCRYPTION_KEY_VERSION'] ?? false,
        ];
        $this->setMasterKey($this->key);
        putenv('STORAGE_ENCRYPTION_KEY_VERSION=1');
        $_ENV['STORAGE_ENCRYPTION_KEY_VERSION'] = '1';

        $this->dir = sys_get_temp_dir() . '/sealed-stream-test-' . bin2hex(random_bytes(6));
        mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);

        foreach ($this->originalEnv as $name => $value) {
            unset($_ENV[$name], $_SERVER[$name]);
            if ($value === false) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
    }

    // -----------------------------------------------------------------
    // Round trip
    // -----------------------------------------------------------------

    public function testAppendedLinesReadBackByteIdentically(): void
    {
        $path   = $this->dir . '/daily.log';
        $expect = '';
        for ($i = 1; $i <= 5; $i++) {
            $line = "line {$i} user=admin ip=10.0.0.1\n";
            $expect .= $line;
            self::assertTrue(SealedStream::append($path, $line), "append {$i}");
        }

        $r = SealedStream::read($path);

        self::assertSame($expect, $r['plaintext']);
        self::assertSame(5, $r['records']);
        self::assertFalse($r['truncated'], 'a cleanly closed stream is not truncated');
    }

    public function testSecondAppendStaysReadableOnAMultiRecordStream(): void
    {
        // The regression this whole trailer change exists for. With only a
        // length PREFIX the writer cannot find the previous record from EOF, so
        // the chain link for record 2 is wrong and the file no longer reads.
        $path = $this->dir . '/chain.log';
        SealedStream::append($path, "first\n");
        SealedStream::append($path, "second\n");
        SealedStream::append($path, "third\n");

        self::assertSame("first\nsecond\nthird\n", SealedStream::read($path)['plaintext']);
    }

    public function testLargeLineRoundTrips(): void
    {
        // Log lines carry stack traces; a record must not be length-limited.
        $path = $this->dir . '/big.log';
        $big  = str_repeat('x', 512 * 1024) . "\n";
        SealedStream::append($path, $big);

        self::assertSame($big, SealedStream::read($path)['plaintext']);
    }

    public function testEmptyAppendCreatesNoFileAtAll(): void
    {
        // An empty append is a genuine no-op. It must not create an empty
        // stream, or every request that logs an empty context line would leave
        // a header-only file behind in storage/logs.
        $path = $this->dir . '/empty.log';
        self::assertTrue(SealedStream::append($path, ''));
        self::assertFileDoesNotExist($path);
    }

    public function testEmptyStreamRoundTrips(): void
    {
        $path = $this->dir . '/none.log';
        $r    = SealedStream::sealBuffer($path, '');

        self::assertSame(0, $r['records']);
        self::assertSame('', SealedStream::read($path)['plaintext']);
    }
    // -----------------------------------------------------------------
    // Confidentiality at rest
    // -----------------------------------------------------------------

    public function testFileIsUnreadableWithoutTheKey(): void
    {
        $path = $this->dir . '/secret.log';
        SealedStream::append($path, "salary=250000 ssn=123-45-6789\n");
        SealedStream::append($path, "salary=310000 ssn=987-65-4321\n");

        $raw = (string) file_get_contents($path);

        self::assertStringStartsWith('MWSS1', $raw, 'format magic is present');
        self::assertStringNotContainsString('salary', $raw, 'no plaintext leaks to disk');
        self::assertStringNotContainsString('123-45-6789', $raw);
        self::assertStringNotContainsString('310000', $raw);
    }

    public function testWrongKeyIsRejectedRatherThanReturningGarbage(): void
    {
        $path = $this->dir . '/wrongkey.log';
        SealedStream::append($path, "line one\n");

        $this->setMasterKey(str_repeat("\x22", 32));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessageMatches('/authentication failed|unavailable/i');
        SealedStream::read($path);
    }

    public function testEachStreamGetsADistinctKeyAndNonce(): void
    {
        $a = $this->dir . '/a.log';
        $b = $this->dir . '/b.log';
        SealedStream::append($a, "same line\n");
        SealedStream::append($b, "same line\n");

        // Identical plaintext must not produce identical ciphertext, or an
        // observer learns the two files hold the same content.
        self::assertNotSame(
            (string) file_get_contents($a),
            (string) file_get_contents($b),
            'identical plaintext in two streams must not be linkable'
        );
    }

    public function testSealedStreamIsNotMistakenableForADocumentContainer(): void
    {
        // A logger must never emit MWSS1 bytes to a browser that asked for a PDF.
        $path = $this->dir . '/doc.log';
        SealedStream::append($path, "not a pdf\n");

        self::assertStringStartsWith('MWSS1', (string) file_get_contents($path));
        self::assertFalse(
            \App\Helpers\StorageEncryption::isEncryptedFile($path),
            'MWSS1 must not be claimed by the MWSC1 document decryptor, or a log '
            . 'fetched as a download would be "decrypted" into MWSS1 bytes'
        );
    }

    // -----------------------------------------------------------------
    // Tamper evidence
    // -----------------------------------------------------------------

    public function testFlippedCiphertextByteIsDetected(): void
    {
        $path = $this->dir . '/tamper.log';
        SealedStream::append($path, "alpha\n");
        SealedStream::append($path, "bravo\n");

        $raw  = (string) file_get_contents($path);
        $at   = $this->headerLength($raw) + 4 + 20; // inside record 1's ciphertext
        $raw[$at] = chr(ord($raw[$at]) ^ 0xFF);
        file_put_contents($path, $raw);

        $this->expectException(\RuntimeException::class);
        SealedStream::read($path);
    }

    public function testReorderedRecordsAreDetected(): void
    {
        $path = $this->dir . '/reorder.log';
        foreach (['one', 'two', 'three'] as $l) {
            SealedStream::append($path, $l . "\n");
        }

        $raw  = (string) file_get_contents($path);
        $hlen = $this->headerLength($raw);

        // Records have DIFFERENT lengths ("one" vs "three"), so each offset has
        // to be re-derived from the prefix rather than assumed. Getting this
        // wrong slices mid-record and produces a file that merely looks torn.
        $off1 = $hlen;
        $n1   = $this->framedLength($raw, $off1);
        $off2 = $off1 + $n1;
        $n2   = $this->framedLength($raw, $off2);
        $off3 = $off2 + $n2;
        $n3   = $this->framedLength($raw, $off3);

        // Swap records 2 and 3. Both still decrypt individually, but the AAD
        // binds each record to its predecessor's IV, so the swap breaks the
        // chain - a property a plain per-record AEAD would not have.
        file_put_contents($path,
            substr($raw, 0, $off2)          // header + record 1
            . substr($raw, $off3, $n3)      // record 3, at its own length
            . substr($raw, $off2, $n2)      // record 2
            . substr($raw, $off3 + $n3)     // nothing left
        );

        $this->expectException(\RuntimeException::class);
        SealedStream::read($path);
    }
    public function testRecordMovedToAnotherStreamIsDetected(): void
    {
        // streamId is in the AAD, so a record lifted from one log into another
        // fails to authenticate even though the master key is the same.
        $a = $this->dir . '/src.log';
        $b = $this->dir . '/dst.log';
        SealedStream::append($a, "alpha\n");
        SealedStream::append($a, "bravo\n");
        SealedStream::sealBuffer($b, "charlie\n");

        $rawA = (string) file_get_contents($a);
        $rawB = (string) file_get_contents($b);
        $hlenA = $this->headerLength($rawA);
        $n    = $this->framedLength($rawA, $hlenA);

        // Splice A's first record into B, in place of B's own.
        file_put_contents($b, substr($rawB, 0, $this->headerLength($rawB)) . substr($rawA, $hlenA, $n));

        $this->expectException(\RuntimeException::class);
        SealedStream::read($b);
    }

    public function testTornTailIsFlaggedAndEarlierRecordsSurvive(): void
    {
        // A writer killed mid-append leaves a partial record. The reader must
        // return the complete records and flag the truncation, not throw and
        // not hand back the partial bytes.
        $path = $this->dir . '/torn.log';
        SealedStream::append($path, "alpha\n");
        SealedStream::append($path, "bravo\n");
        SealedStream::append($path, "charlie\n");

        $raw = (string) file_get_contents($path);
        file_put_contents($path, substr($raw, 0, strlen($raw) - 10));

        $r = SealedStream::read($path);

        self::assertTrue($r['truncated'], 'an incomplete trailing record is reported');
        self::assertSame("alpha\nbravo\n", $r['plaintext'], 'complete records are still returned');
    }

    public function testTruncatedLengthPrefixIsFlaggedNotMisparsed(): void
    {
        // 3 stray bytes cannot form a uint32 length. Returning them as plaintext
        // would leak bytes out of a corrupt file.
        $path = $this->dir . '/stub.log';
        SealedStream::append($path, "alpha\n");
        file_put_contents($path, (string) file_get_contents($path) . "\x00\x01\x02");

        $r = SealedStream::read($path);

        self::assertTrue($r['truncated']);
        self::assertSame("alpha\n", $r['plaintext']);
    }

    public function testAbandonedAppendDoesNotCorruptTheStream(): void
    {
        // A crash between the two halves of a framed write. The next reader must
        // not merge the debris into the previous record.
        $path = $this->dir . '/abandoned.log';
        SealedStream::append($path, "alpha\n");

        // A length prefix promising more than exists, with nothing after it.
        file_put_contents($path, (string) file_get_contents($path) . pack('N', 4096) . random_bytes(50));

        $r = SealedStream::read($path);

        self::assertTrue($r['truncated']);
        self::assertSame("alpha\n", $r['plaintext'], 'the torn record is skipped, not merged');
    }
    // -----------------------------------------------------------------
    // sealBuffer (bulk migration path)
    // -----------------------------------------------------------------

    public function testSealBufferMatchesSourceByteForByte(): void
    {
        $path = $this->dir . '/bulk.log';
        $text = "first line\nsecond line\nthird line\n";

        $r = SealedStream::sealBuffer($path, $text);

        self::assertSame(3, $r['records'], 'no phantom record from the trailing newline');
        self::assertSame(hash('sha256', $text), $r['sha256'], 'source digest is reported for verification');
        self::assertSame($text, SealedStream::read($path)['plaintext']);
    }

    public function testSealBufferHandlesCRLFAndBlankLines(): void
    {
        $path = $this->dir . '/crlf.log';
        $text = "alpha\r\n\r\nbeta\r\n";

        SealedStream::sealBuffer($path, $text);

        // Blank lines must survive, because log files contain them.
        self::assertSame($text, SealedStream::read($path)['plaintext']);
    }

    public function testSealBufferedStreamCanBeAppendedTo(): void
    {
        $path = $this->dir . '/mixed.log';
        SealedStream::sealBuffer($path, "one\ntwo\n");
        SealedStream::append($path, "three\n");

        self::assertSame("one\ntwo\nthree\n", SealedStream::read($path)['plaintext']);
    }

    // -----------------------------------------------------------------
    // Migration compatibility
    // -----------------------------------------------------------------

    public function testReadOrPlaintextPassesBothStates(): void
    {
        $sealed = $this->dir . '/sealed.log';
        SealedStream::append($sealed, "secret line\n");

        $plain = $this->dir . '/plain.log';
        file_put_contents($plain, "secret line\n");

        // While the migration is half-done the directory is mixed, so every
        // reader must tolerate both states rather than assuming one.
        self::assertSame("secret line\n", SealedStream::readOrPlaintext($sealed)['plaintext']);
        self::assertSame("secret line\n", SealedStream::readOrPlaintext($plain)['plaintext']);
    }

    public function testIsSealedDistinguishesTheTwoStates(): void
    {
        $sealed = $this->dir . '/sealed.log';
        SealedStream::append($sealed, "x\n");
        $plain = $this->dir . '/plain.log';
        file_put_contents($plain, "x\n");

        self::assertTrue(SealedStream::isSealed($sealed));
        self::assertFalse(SealedStream::isSealed($plain));
    }

    // -----------------------------------------------------------------
    // Streaming seal/open (the large-file migration path)
    //
    // sealStream/readToFile exist because sealBuffer/read hold the whole file
    // in memory, which is fatal for laravel.log at 310 MB. These assert the
    // two produce IDENTICAL plaintext, so the migration path cannot silently
    // differ from the small-file path.
    // -----------------------------------------------------------------

    public function testSealStreamRoundTripsExactly(): void
    {
        $src  = $this->dir . '/source.log';
        $text = "alpha\nbravo\ncharlie\n";
        file_put_contents($src, $text);

        $sealed = $this->dir . '/sealed.log';
        $r = SealedStream::sealStream($src, $sealed);

        self::assertSame(3, $r['records']);
        self::assertSame(strlen($text), $r['bytes']);
        self::assertSame(hash('sha256', $text), $r['sha256']);

        $out = $this->dir . '/recovered.log';
        $o   = SealedStream::readToFile($sealed, $out);

        self::assertFalse($o['truncated']);
        self::assertSame(3, $o['records']);
        self::assertSame($text, (string) file_get_contents($out), 'recovered bytes are identical');
    }

    public function testSealStreamPreservesFileWithoutTrailingNewline(): void
    {
        // fgets() omits the "\n" on the final line of such a file. If the
        // streaming path added one anyway, every recovered log would silently
        // gain a byte and the sha256 comparison would fail at migration time.
        $src  = $this->dir . '/noeol.log';
        $text = "alpha\nbravo";
        file_put_contents($src, $text);

        $sealed = $this->dir . '/noeol.sealed';
        $r      = SealedStream::sealStream($src, $sealed);

        self::assertSame(2, $r['records']);
        self::assertSame(strlen($text), $r['bytes'], 'byte count matches the source exactly');

        $out = $this->dir . '/noeol.out';
        SealedStream::readToFile($sealed, $out);

        self::assertSame($text, (string) file_get_contents($out));
    }

    public function testSealStreamHandlesEmptyAndSingleLineFiles(): void
    {
        $empty = $this->dir . '/empty.src';
        file_put_contents($empty, '');
        $sealedEmpty = $this->dir . '/empty.sealed';
        self::assertSame(0, SealedStream::sealStream($empty, $sealedEmpty)['records']);

        $outEmpty = $this->dir . '/empty.out';
        $o = SealedStream::readToFile($sealedEmpty, $outEmpty);
        self::assertSame(0, $o['records']);
        self::assertSame('', (string) file_get_contents($outEmpty));

        $one = $this->dir . '/one.src';
        file_put_contents($one, "just one line\n");
        $sealedOne = $this->dir . '/one.sealed';
        self::assertSame(1, SealedStream::sealStream($one, $sealedOne)['records']);
    }

    public function testSealStreamLeavesTheSourceUntouched(): void
    {
        // The migration contract: the caller deletes the plaintext only after
        // verifying, so sealing must never write to the source.
        $src  = $this->dir . '/src.log';
        $text = "keep me\n";
        file_put_contents($src, $text);
        $before = hash_file('sha256', $src);

        SealedStream::sealStream($src, $this->dir . '/out.log');

        self::assertFileExists($src);
        self::assertSame($before, hash_file('sha256', $src), 'source is byte-identical after sealing');
    }
    public function testReadToFileRejectsTheWrongKeyAndLeavesNoOutput(): void
    {
        $src  = $this->dir . '/secret.src';
        file_put_contents($src, "confidential line\n");
        $sealed = $this->dir . '/secret.sealed';
        SealedStream::sealStream($src, $sealed);

        $out = $this->dir . '/must-not-appear.out';
        $this->setMasterKey(str_repeat("\x33", 32));

        try {
            SealedStream::readToFile($sealed, $out);
            self::fail('a wrong key must not decrypt');
        } catch (\RuntimeException $e) {
            self::assertMatchesRegularExpression(
                '/authentication failed|unavailable/i',
                $e->getMessage()
            );
        }

        // A partial restore that looks complete is the dangerous failure here.
        self::assertFileDoesNotExist($out, 'no partial plaintext left behind');
    }

    public function testAppendToALargeStreamDoesNotReReadTheWholeFile(): void
    {
        // ensureHeader() once did readAt($fh, 0, $size), so every append cost
        // O(file size): one log line against a 310 MB laravel.log meant reading
        // 310 MB. A wall-clock assertion is inherently flaky, so this asserts
        // the structural invariant instead - a file far larger than any
        // plausible header still appends correctly and quickly.
        $src  = $this->dir . '/large.src';
        $line = str_repeat('payload ', 200);
        $fh   = fopen($src, 'wb');
        for ($i = 0; $i < 20000; $i++) {
            fwrite($fh, $line . $i . "\n");
        }
        fclose($fh);

        $sealed = $this->dir . '/large.sealed';
        $r      = SealedStream::sealStream($src, $sealed);
        self::assertSame(20000, $r['records']);

        $started = microtime(true);
        self::assertTrue(SealedStream::append($sealed, "appended line\n"));
        $elapsed = microtime(true) - $started;

        // Generous ceiling: an O(size) read of a ~4 MB file would still be fast
        // here, so this is a smoke test against an accidental full re-read
        // rather than a benchmark. The property under test is correctness
        // after many records, which the O(1) back-seek must preserve.
        self::assertLessThan(2.0, $elapsed, 'append should not scale with file size');

        $out = $this->dir . '/large.out';
        $o   = SealedStream::readToFile($sealed, $out);
        self::assertSame(20001, $o['records']);
        self::assertFalse($o['truncated']);

        // The recovered file is the original PLUS the appended line, so a whole
        // -file hash cannot equal the source's. What must hold is that the
        // original bytes are still a verbatim prefix: the append rewrote
        // nothing that came before it.
        $recovered = (string) file_get_contents($out);
        $source    = (string) file_get_contents($src);
        self::assertStringStartsWith(
            $source,
            $recovered,
            'appending must not disturb any earlier record'
        );
        self::assertSame(
            "appended line\n",
            substr($recovered, strlen($source)),
            'and the new record is exactly what was appended'
        );
    }

    public function testSealStreamRejectsAMissingSource(): void
    {
        $this->expectException(\RuntimeException::class);
        SealedStream::sealStream($this->dir . '/nope.log', $this->dir . '/out.log');
    }

    // -----------------------------------------------------------------
    // Helpers
    // -----------------------------------------------------------------


    /**
     * Total size of one framed record: 4-byte prefix + body + 4-byte trailer.
     */
    private function framedLength(string $raw, int $atHeaderEnd): int
    {
        $len = unpack('N', substr($raw, $atHeaderEnd, 4))[1];
        return 4 + $len + 4;
    }

    /**
     * Header layout: magic(5) version(1) keyVersion(1) wrappedLen(4)
     * keyNonce(12) wrapped(wrappedLen) streamId(16).
     */
    private function headerLength(string $raw): int
    {
        $wrappedLen = unpack('N', substr($raw, 7, 4))[1];
        return 5 + 1 + 1 + 4 + 12 + $wrappedLen + 16;
    }
}
