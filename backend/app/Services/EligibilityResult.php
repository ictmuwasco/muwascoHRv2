<?php

declare(strict_types=1);

namespace App\Services;

/**
 * Immutable-by-convention eligibility decision value object.
 * (No readonly keyword: deployment targets PHP 8.0.)
 */
class EligibilityResult
{
    /** @var bool */
    public $eligible;
    /** @var string */
    public $reason;
    /** @var string */
    public $detail;

    public function __construct(bool $eligible, string $reason, string $detail = '')
    {
        $this->eligible = $eligible;
        $this->reason   = $reason;
        $this->detail   = $detail;
    }

    public function toArray(): array
    {
        return [
            'eligible' => $this->eligible,
            'reason'   => $this->reason,
            'detail'   => $this->detail,
        ];
    }
}
