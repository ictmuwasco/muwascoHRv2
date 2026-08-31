<?php

declare(strict_types=1);

namespace App\Controllers\Meeting;

use App\Controllers\BaseController;
use App\Services\MeetingMinutesService;

/**
 * MeetingMinutesController
 *
 * Thin HTTP layer over MeetingMinutesService. All authorization and business
 * rules live in the service — it computes per-user capability flags
 * (can_create / can_edit_draft / can_publish / can_reopen / can_view) and
 * throws InvalidArgumentException carrying HTTP status codes (401/403/404/409/422).
 * This controller only resolves the authenticated user, delegates, and maps
 * exceptions, matching the MeetingController error conventions.
 */
class MeetingMinutesController extends BaseController
{
    private ?MeetingMinutesService $minutesService = null;

    /** Lazy service accessor (avoids overriding BaseController's constructor). */
    private function minutes(): MeetingMinutesService
    {
        if ($this->minutesService === null) {
            $this->minutesService = new MeetingMinutesService();
        }
        return $this->minutesService;
    }

    /** GET /meetings/{id}/minutes/status — capability flags + minutes existence. */
    public function statusAction(int $id)
    {
        return $this->guard(function () use ($id) {
            return $this->success(
                $this->minutes()->status((int) $id, $this->getUserId())
            );
        });
    }

    /** GET /meetings/{id}/minutes/options — employee + department pickers. */
    public function optionsAction(int $id)
    {
        return $this->guard(function () use ($id) {
            return $this->success(
                $this->minutes()->options((int) $id)
            );
        });
    }

    /** GET /meetings/{id}/minutes — full minutes (draft for managers, published for confirmed attendees). */
    public function viewAction(int $id)
    {
        return $this->guard(function () use ($id) {
            return $this->success(
                $this->minutes()->view((int) $id, $this->getUserId())
            );
        });
    }

    /** POST /meetings/{id}/minutes — create draft minutes. */
    public function createAction(int $id)
    {
        return $this->guard(function () use ($id) {
            $userId = $this->getUserId();
            if (!$userId) {
                return $this->unauthorized();
            }
            $raw = $this->getJsonBody() ?: [];
            return $this->success(
                $this->minutes()->create((int) $id, $raw, (int) $userId),
                'Meeting minutes saved as draft.'
            );
        });
    }

    /** PUT /meetings/{id}/minutes — update draft (or amend published with reason). */
    public function updateAction(int $id)
    {
        return $this->guard(function () use ($id) {
            $userId = $this->getUserId();
            if (!$userId) {
                return $this->unauthorized();
            }
            $raw = $this->getJsonBody() ?: [];
            return $this->success(
                $this->minutes()->update((int) $id, $raw, (int) $userId),
                'Meeting minutes updated.'
            );
        });
    }

    /** POST /meetings/{id}/minutes/publish — publish draft minutes. */
    public function publishAction(int $id)
    {
        return $this->guard(function () use ($id) {
            $userId = $this->getUserId();
            if (!$userId) {
                return $this->unauthorized();
            }
            $raw = $this->getJsonBody() ?: [];
            return $this->success(
                $this->minutes()->publish((int) $id, $raw, (int) $userId),
                'Meeting minutes published.'
            );
        });
    }

    /** POST /meetings/{id}/minutes/reopen — reopen published minutes for amendment. */
    public function reopenAction(int $id)
    {
        return $this->guard(function () use ($id) {
            $userId = $this->getUserId();
            if (!$userId) {
                return $this->unauthorized();
            }
            $raw = $this->getJsonBody() ?: [];
            $reason = trim((string) ($raw['reason'] ?? ''));
            return $this->success(
                $this->minutes()->reopen((int) $id, $reason, (int) $userId),
                'Meeting minutes reopened for amendment.'
            );
        });
    }

    /**
     * Shared execution wrapper: maps the service's InvalidArgumentException
     * codes onto proper HTTP responses; everything else logs and 500s.
     */
    private function guard(callable $action)
    {
        try {
            return $action();
        } catch (\InvalidArgumentException $e) {
            $code = $e->getCode();
            $status = ($code >= 400 && $code <= 599) ? $code : 400;
            return $this->error($e->getMessage(), $status);
        } catch (\Throwable $e) {
            logger()->error('MeetingMinutesController: ' . $e->getMessage());
            return $this->error('An unexpected error occurred while processing the meeting minutes.', 500);
        }
    }
}
