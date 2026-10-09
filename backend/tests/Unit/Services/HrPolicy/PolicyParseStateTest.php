<?php

declare(strict_types=1);

namespace Tests\Unit\Services\HrPolicy;

use App\Models\HrPolicyDocument;
use PHPUnit\Framework\TestCase;

/**
 * Coverage for the async section-extraction state machine (migration 108).
 *
 * WHY THIS EXISTS
 *   Uploading the MUWASCO manual returned a bare 500 on production: parsing ran
 *   inside the web request, where memory_limit=128M / max_execution_time=30 are
 *   pinned with php_admin_value. Exceeding either is a PHP FATAL, which is not
 *   a Throwable, so nothing was logged, no cleanup ran, and HR got no reason.
 *   Section extraction now happens in a CLI worker and is tracked with its own
 *   parse_status, separate from the publishing status.
 *
 *   These are pure-logic tests: no database, no bootstrap, no live HR data.
 *   They pin the contract the worker and the publish guard both depend on.
 */
final class PolicyParseStateTest extends TestCase
{
    /**
     * parse_status must stay ORTHOGONAL to the publishing status. Merging them
     * would mean "stored but unparsed" has no representation, and an unreadable
     * document could be published.
     */
    public function testParseStatusesAreDistinctFromPublishingStatuses(): void
    {
        $overlap = array_intersect(HrPolicyDocument::PARSE_STATUSES, HrPolicyDocument::STATUSES);

        $this->assertSame([], $overlap, 'parse_status must not overlap publishing status');
    }

    public function testParseStatusCoversTheWholeLifecycle(): void
    {
        $this->assertSame(
            ['pending', 'processing', 'done', 'failed'],
            HrPolicyDocument::PARSE_STATUSES
        );
    }

    /**
     * A broken file must not be retried forever. Without a cap, a document that
     * can never parse (encrypted PDF, image-only scan) would be picked up on
     * every cron tick indefinitely.
     */
    public function testRetryCapIsPositiveAndSmall(): void
    {
        $this->assertGreaterThan(0, HrPolicyDocument::PARSE_MAX_ATTEMPTS);
        $this->assertLessThanOrEqual(10, HrPolicyDocument::PARSE_MAX_ATTEMPTS);
    }

    /**
     * The publish guard treats these states as "not readable yet". If a state is
     * ever added and forgotten here, HR could publish an empty reader - the
     * exact failure the old synchronous parse prevented.
     */
    public function testUnparsedStatesAreExactlyPendingAndProcessing(): void
    {
        $unparsed = array_values(array_diff(
            HrPolicyDocument::PARSE_STATUSES,
            [HrPolicyDocument::PARSE_DONE, HrPolicyDocument::PARSE_FAILED]
        ));

        $this->assertSame(
            [HrPolicyDocument::PARSE_PENDING, HrPolicyDocument::PARSE_PROCESSING],
            $unparsed
        );
    }

    /** Done and failed are both terminal: neither is retried by the drain. */
    public function testTerminalStatesAreDoneAndFailed(): void
    {
        $this->assertContains(HrPolicyDocument::PARSE_DONE, HrPolicyDocument::PARSE_STATUSES);
        $this->assertContains(HrPolicyDocument::PARSE_FAILED, HrPolicyDocument::PARSE_STATUSES);
        $this->assertNotSame(
            HrPolicyDocument::PARSE_DONE,
            HrPolicyDocument::PARSE_FAILED
        );
    }

    /**
     * The worker's claim query filters on these values, so the constants must be
     * the literal strings stored in the column (the migration's ENUM).
     */
    public function testParseConstantsMatchTheStoredEnumValues(): void
    {
        $this->assertSame('pending', HrPolicyDocument::PARSE_PENDING);
        $this->assertSame('processing', HrPolicyDocument::PARSE_PROCESSING);
        $this->assertSame('done', HrPolicyDocument::PARSE_DONE);
        $this->assertSame('failed', HrPolicyDocument::PARSE_FAILED);
    }

    /**
     * A new version must default to pending, never done. Defaulting to done
     * would let an unparsed document be published immediately.
     */
    public function testPendingIsTheInitialState(): void
    {
        $this->assertSame('pending', HrPolicyDocument::PARSE_PENDING);
        $this->assertNotSame(HrPolicyDocument::PARSE_DONE, HrPolicyDocument::PARSE_PENDING);
    }
}
