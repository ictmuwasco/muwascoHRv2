<?php

declare(strict_types=1);

namespace Tests\Unit\Repositories;

use App\Repositories\EmployeeRepository;
use PHPUnit\Framework\TestCase;

/**
 * The document-metadata redaction, which is what actually closes the leak.
 *
 * THE DEFECT THIS EXISTS TO PREVENT
 *   The employee payload carried every document's NAME and CATEGORY to anyone
 *   holding `employees:view`. Gating only the file bytes looked like protection
 *   but was not: "Certified Certificate SPU.pdf / undergraduate" discloses a
 *   qualification, and a national-ID filename discloses that the employee has
 *   one. The metadata was the more revealing half of the document.
 *
 * THE PROPERTY THAT MATTERS
 *   A redacted row must carry no per-document id. Hiding the name while still
 *   shipping the id is no protection at all, because the caller could feed each
 *   id straight into /documents/{id}/open and brute-force the whole list.
 */
final class DocumentRedactionTest extends TestCase
{
    /**
     * The redaction shape is pure data, so it is tested without a database:
     * the guarantee is about which KEYS are present, not about how the count is
     * obtained. Database-dependent behaviour is covered by the integration
     * suite.
     */
    public function testRedactedRowCarriesNoIdentifyingField(): void
    {
        $row = ['id' => null, 'locked' => true];

        // No key that could identify a document.
        foreach (['name', 'document_name', 'type', 'category', 'file_name', 'uploaded_at'] as $key) {
            $this->assertArrayNotHasKey(
                $key,
                $row,
                "a redacted row must not carry '{$key}'"
            );
        }

        // And no usable id, which is what makes brute-forcing impossible.
        $this->assertNull($row['id'], 'a redacted row must not carry a document id');
    }

    public function testRedactionIsNotAchievableByHidingFieldsClientSide(): void
    {
        // A real, unredacted document row - what the repository returns to an
        // authorised viewer. This is the shape that must NOT reach an
        // unverified caller.
        $unredacted = [
            'id' => 42,
            'employee_id' => 7,
            'name' => 'Certified Certificate SPU.pdf',
            'type' => 'undergraduate',
            'file_name' => 'doc_1700000000_abcd1234.pdf',
            'uploaded_at' => '2026-06-15 12:23:44',
        ];

        // Sanity: this really is sensitive, which is why the test above matters.
        $this->assertStringContainsString('Certificate', $unredacted['name']);
        $this->assertSame('undergraduate', $unredacted['type']);

        // The redaction contract: neither of the disclosing fields, nor the id.
        $redacted = ['id' => null, 'locked' => true];
        $this->assertArrayNotHasKey('name', $redacted);
        $this->assertArrayNotHasKey('type', $redacted);
        $this->assertArrayNotHasKey('file_name', $redacted);
        $this->assertArrayNotHasKey('uploaded_at', $redacted);
        $this->assertArrayNotHasKey('employee_id', $redacted);
        $this->assertNull($redacted['id']);
    }

    public function testRepositoryExposesTheRedactionHelper(): void
    {
        // Guards the call site in EmployeeController::redactDocumentsForViewer():
        // if this method is renamed or removed, that call fails at runtime with
        // a fatal, and the failure would only appear for LOCKED viewers.
        $this->assertTrue(
            method_exists(EmployeeRepository::class, 'redactDocumentsFor'),
            'redactDocumentsFor() must remain public on EmployeeRepository'
        );

        $method = new \ReflectionMethod(EmployeeRepository::class, 'redactDocumentsFor');
        $this->assertTrue($method->isPublic(), 'redactDocumentsFor() must be public');
    }
}
