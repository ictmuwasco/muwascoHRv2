<?php

declare(strict_types=1);

namespace App\Services\Notification;

/**
 * A single delivery request handed from the router to a channel.
 *
 * Adding EMAIL / WHATSAPP / IN_APP later = new ChannelInterface
 * implementation; this request shape and the attendance logic above
 * it stay untouched.
 */
class NotificationRequest
{
    /** @var int */
    public $userId;
    /** @var int|null */
    public $employeeId;
    /** @var string */
    public $employeeName;
    /** @var string */
    public $notificationType;
    /** @var string */
    public $stage;
    /** @var string */
    public $businessDate;
    /** @var string|null Normalised E.164 phone (SMS channel); null when absent/invalid. */
    public $phone;
    /** @var array Extra template variables, e.g. clock-in URL. */
    public $variables;

    public function __construct(
        int $userId,
        ?int $employeeId,
        string $employeeName,
        string $notificationType,
        string $stage,
        string $businessDate,
        ?string $phone = null,
        array $variables = []
    ) {
        $this->userId           = $userId;
        $this->employeeId       = $employeeId;
        $this->employeeName     = $employeeName;
        $this->notificationType = $notificationType;
        $this->stage            = $stage;
        $this->businessDate     = $businessDate;
        $this->phone            = $phone;
        $this->variables        = $variables;
    }
}
