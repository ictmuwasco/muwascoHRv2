<?php

declare(strict_types=1);

namespace App\Services\Notification;

/**
 * Reminder message templates.
 *
 * Defaults are sensible; override via env without touching code.
 * Supported variables: {employee_name} {date} {organization_name}
 * SMS copy is deliberately short (single segment where possible).
 */
class ReminderTemplateService
{
    public function pushTitle(): string
    {
        return (string) env('ATTENDANCE_PUSH_TITLE', '🔔 Attendance Reminder');
    }

    public function pushBody(): string
    {
        return (string) env(
            'ATTENDANCE_PUSH_BODY',
            'Good morning {employee_name}. Please remember to clock in for today.'
        );
    }

    public function smsBody(): string
    {
        return (string) env(
            'ATTENDANCE_SMS_BODY',
            'HR Attendance Reminder: Good morning {employee_name}. Please remember to clock in for today.'
        );
    }

    /** Render a template string with the given variables. */
    public function render(string $template, array $variables = []): string
    {
        $replacements = array_merge([
            'employee_name'     => '',
            'date'              => '',
            'organization_name' => (string) env('APP_ORGANIZATION_NAME', 'MUWASCO'),
            'attendance_url'    => '',
        ], $variables);

        foreach ($replacements as $key => $value) {
            $template = str_replace('{' . $key . '}', (string) $value, $template);
        }
        return $template;
    }

    /** Ready-to-send Web Push JSON payload. */
    public function buildPushPayload(NotificationRequest $request): string
    {
        return json_encode([
            'title' => $this->render($this->pushTitle(), $request->variables),
            'body'  => $this->render($this->pushBody(), $request->variables),
            'tag'   => 'attendance-clock-in-' . $request->businessDate,
            // Click target resolved by the service worker against app base.
            'data'  => ['url' => '/attendance'],
        ]) ?: '{}';
    }

    /** Ready-to-send SMS text. */
    public function buildSmsText(NotificationRequest $request): string
    {
        return $this->render($this->smsBody(), $request->variables);
    }
}
