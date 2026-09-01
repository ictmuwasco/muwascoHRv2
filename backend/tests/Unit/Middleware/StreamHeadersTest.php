<?php

declare(strict_types=1);

namespace Tests\Unit\Middleware;

use Tests\TestCase;
use App\Middleware\SecurityMiddleware;

/**
 * Phase 7 regression tests — file-streaming header hardening (P7-7).
 *
 * Stored HR documents can carry client-influenced names and, if an upload
 * allowlist ever regresses, hostile HTML/SVG content. Every streaming
 * endpoint must therefore emit a sandbox CSP, nosniff, no-store cache
 * control, and a Content-Disposition that downloads everything except
 * preview-safe PDFs/images.
 */
class StreamHeadersTest extends TestCase
{
    private function headerValue(array $headers, string $name): ?string
    {
        foreach ($headers as $header) {
            if (stripos($header, $name . ':') === 0) {
                return trim(substr($header, strlen($name) + 1));
            }
        }
        return null;
    }

    public function test_pdf_is_safe_to_preview_inline(): void
    {
        $headers = SecurityMiddleware::streamHeaderMap('application/pdf', 'leave-support-letter.pdf');

        $this->assertSame('sandbox', $this->headerValue($headers, 'Content-Security-Policy'));
        $this->assertSame('nosniff', $this->headerValue($headers, 'X-Content-Type-Options'));
        $this->assertSame('private, no-store', $this->headerValue($headers, 'Cache-Control'));
        $this->assertStringStartsWith('inline; filename="', $this->headerValue($headers, 'Content-Disposition'));
    }

    public function test_arbitrary_mime_is_forced_to_download(): void
    {
        $headers = SecurityMiddleware::streamHeaderMap('text/html', 'evil-upload.html');

        $this->assertStringStartsWith('attachment; filename="', $this->headerValue($headers, 'Content-Disposition'));
    }

    public function test_svg_js_xml_and_unknown_types_never_render_inline(): void
    {
        foreach (['image/svg+xml', 'text/javascript', 'application/xml', 'application/octet-stream'] as $mime) {
            $headers = SecurityMiddleware::streamHeaderMap($mime, 'file.bin');
            $this->assertStringStartsWith(
                'attachment;',
                $this->headerValue($headers, 'Content-Disposition'),
                "$mime must never render inline"
            );
            $this->assertSame('sandbox', $this->headerValue($headers, 'Content-Security-Policy'));
        }
    }

    public function test_force_download_keeps_pdf_as_attachment(): void
    {
        // Leave attachments / other flows that must always download.
        $headers = SecurityMiddleware::streamHeaderMap('application/pdf', 'doc.pdf', true);

        $this->assertStringStartsWith('attachment;', $this->headerValue($headers, 'Content-Disposition'));
    }

    public function test_filename_is_stripped_of_crlf_and_quotes(): void
    {
        // Header-injection attempt via a crafted client-supplied filename.
        $headers = SecurityMiddleware::streamHeaderMap('application/pdf', "report.pdf\"\r\nX-Evil: 1.pdf", true);
        $disposition = (string) $this->headerValue($headers, 'Content-Disposition');

        // A single well-formed quoted filename — no orphan quotes, no CR/LF.
        $this->assertMatchesRegularExpression(
            '/^attachment; filename="[^"]*"$/',
            $disposition
        );

        // The actual filename no longer contains quotes or newlines.
        $filename = explode('filename="', $disposition)[1] ?? '';
        $filename = substr($filename, 0, -1);
        $this->assertStringNotContainsString('"', $filename);
        $this->assertStringNotContainsString("\r", $filename);
        $this->assertStringNotContainsString("\n", $filename);
    }
}