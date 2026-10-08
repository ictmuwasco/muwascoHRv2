<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HrPolicy;

use App\Services\HrPolicy\PolicyFileService;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for the HR policy upload failure surface.
 *
 * These are pure-logic tests: no database, no application bootstrap, no live
 * HR data. The behaviour pinned here is what HR actually sees on the two
 * production failures:
 *   - every PHP upload error code maps to an actionable message (never the
 *     old generic 'No file uploaded or upload error.')
 *   - a POST body discarded by post_max_size is detected via CONTENT_LENGTH
 *     instead of surfacing as a misleading validation error
 *   - legacy OLE2 binaries are rejected by signature, even renamed to .docx
 */
final class PolicyFileServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        unset($_SERVER['CONTENT_LENGTH'], $_POST, $_FILES);
        $_POST = [];
        $_FILES = [];
    }

    public function testIniSizeMessageNamesTheLimitAndTheFix(): void
    {
        $_ENV['HR_POLICY_MAX_MB'] = '20';
        $_SERVER['HR_POLICY_MAX_MB'] = '20';
        putenv('HR_POLICY_MAX_MB=20');

        $msg = PolicyFileService::uploadErrorMessage(UPLOAD_ERR_INI_SIZE);

        $this->assertStringContainsString('too large', $msg);
        $this->assertStringContainsString('20MB', $msg);
        $this->assertStringContainsString('upload_max_filesize', $msg);
    }

    public function testFormSizeMapsToTheSameActionableMessage(): void
    {
        $this->assertSame(
            PolicyFileService::uploadErrorMessage(UPLOAD_ERR_INI_SIZE),
            PolicyFileService::uploadErrorMessage(UPLOAD_ERR_FORM_SIZE)
        );
    }

    public function testPartialAndMissingFileMessagesGuideRetry(): void
    {
        $this->assertStringContainsString(
            'interrupted',
            PolicyFileService::uploadErrorMessage(UPLOAD_ERR_PARTIAL)
        );
        $this->assertStringContainsString(
            'No file was received',
            PolicyFileService::uploadErrorMessage(UPLOAD_ERR_NO_FILE)
        );
    }

    public function testTruncatedPostDetectedWhenBodySentButNothingArrived(): void
    {
        $_SERVER['CONTENT_LENGTH'] = '25000000';
        $_POST = [];
        $_FILES = [];

        $msg = PolicyFileService::truncatedPostMessage();

        $this->assertNotNull($msg);
        $this->assertStringContainsString('post_max_size', $msg);
    }

    public function testTruncatedPostNotReportedWhenFilesArrived(): void
    {
        $_SERVER['CONTENT_LENGTH'] = '25000000';
        $_POST = [];
        $_FILES = ['file' => ['error' => UPLOAD_ERR_OK]];

        $this->assertNull(PolicyFileService::truncatedPostMessage());
    }

    public function testTruncatedPostNotReportedForEmptyBody(): void
    {
        unset($_SERVER['CONTENT_LENGTH']);
        $_POST = [];
        $_FILES = [];

        $this->assertNull(PolicyFileService::truncatedPostMessage());
    }

    public function testStoreRejectsLegacyDocByExtension(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'policy');
        file_put_contents($tmp, "\xD0\xCF\x11\xE0" . str_repeat("\x00", 100));

        try {
            PolicyFileService::store([
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp),
                'name' => 'manual.doc',
                'tmp_name' => $tmp,
            ]);
            $this->fail('Expected InvalidArgumentException for legacy .doc');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Save As', $e->getMessage());
        } finally {
            @unlink($tmp);
        }
    }

    public function testStoreRejectsOle2PayloadRenamedToDocx(): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'policy');
        file_put_contents($tmp, "\xD0\xCF\x11\xE0" . str_repeat("\x00", 100));

        try {
            PolicyFileService::store([
                'error' => UPLOAD_ERR_OK,
                'size' => filesize($tmp),
                'name' => 'manual.docx',
                'tmp_name' => $tmp,
            ]);
            $this->fail('Expected InvalidArgumentException for OLE2 renamed to .docx');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Save As', $e->getMessage());
        } finally {
            @unlink($tmp);
        }
    }
}
