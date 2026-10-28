<?php

namespace App\Scheduler;

use App\Config\Config;
use App\Http\ApiError;
use App\Mail\Mail;
use App\Models\AuthRequest;
use App\Models\Cipher;
use App\Models\DeviceType;
use App\Models\EmergencyAccess;
use App\Models\Event;
use App\Models\Send;
use App\Models\SsoAuth;
use App\Models\TwoFactorDuoContext;
use App\Models\TwoFactorIncomplete;
use App\Models\User;
use App\Support\Dates;
use InvalidArgumentException;

/**
 * The jobs schedule_jobs runs (src/main.rs), each named after its setting
 * like the job: items of the parity matrix.
 */
final class Jobs
{
    /**
     * The setting holding each job's schedule, in the order schedule_jobs adds
     * them. Emergency requests are granted before reminders are sent, so that
     * no one is reminded of a request that is granted anyway.
     */
    public const array SCHEDULES = [
        'send_purge' => 'send_purge_schedule',
        'trash_purge' => 'trash_purge_schedule',
        'incomplete_2fa' => 'incomplete_2fa_schedule',
        'emergency_request_timeout' => 'emergency_request_timeout_schedule',
        'emergency_notification_reminder' => 'emergency_notification_reminder_schedule',
        'auth_request_purge' => 'auth_request_purge_schedule',
        'duo_context_purge' => 'duo_context_purge_schedule',
        'event_cleanup' => 'event_cleanup_schedule',
        'purge_incomplete_sso_auth' => 'purge_incomplete_sso_auth',
    ];

    /**
     * What schedule_jobs checks besides a schedule being set.
     */
    public static function enabled(string $name, Config $config): bool
    {
        return match ($name) {
            'duo_context_purge' => $config->bool('_enable_duo'),
            'event_cleanup' => $config->bool('org_events_enabled') && ! in_array($config->optionalInt('events_days_retain'), [null, 0], true),
            default => true,
        };
    }

    public static function run(string $name): void
    {
        match ($name) {
            'send_purge' => self::purgeSends(),
            'trash_purge' => self::purgeTrashedCiphers(),
            'incomplete_2fa' => self::sendIncomplete2faNotifications(),
            'emergency_request_timeout' => self::emergencyRequestTimeoutJob(),
            'emergency_notification_reminder' => self::emergencyNotificationReminderJob(),
            'auth_request_purge' => self::purgeAuthRequests(),
            'duo_context_purge' => self::purgeDuoContexts(),
            'event_cleanup' => self::eventCleanupJob(),
            'purge_incomplete_sso_auth' => self::purgeIncompleteSsoAuth(),
            default => throw new InvalidArgumentException("Unknown job {$name}"),
        };
    }

    /**
     * purge_sends (src/api/core/sends.rs)
     */
    public static function purgeSends(): void
    {
        Send::purge();
    }

    /**
     * purge_trashed_ciphers (src/api/core/ciphers.rs)
     */
    public static function purgeTrashedCiphers(): void
    {
        Cipher::purgeTrash();
    }

    /**
     * send_incomplete_2fa_notifications (src/api/core/two_factor/mod.rs): a
     * login that was not completed may mean that the master password is known
     * to someone else.
     */
    public static function sendIncomplete2faNotifications(): void
    {
        $config = app(Config::class);
        $timeLimit = $config->int('incomplete_2fa_time_limit');
        if ($timeLimit === 0 || ! $config->mailEnabled()) {
            return;
        }

        foreach (TwoFactorIncomplete::findLoginsBefore(Dates::now()->modify("-{$timeLimit} minutes")) as $login) {
            $user = User::findByUuid($login->user_uuid);
            if ($user === null) {
                logger()->error("User '{$login->user_uuid}', not found!");

                continue;
            }
            logger()->info("User {$user->email} did not complete a 2FA login within the configured time limit. IP: {$login->ip_address}");

            try {
                Mail::sendIncomplete2faLogin($user->email, $login->ip_address, $login->login_time, $login->device_name, DeviceType::fromInt($login->device_type)->displayName());
            } catch (ApiError $error) {
                logger()->error("Error sending incomplete 2FA email: {$error->getMessage()}");

                continue;
            }
            try {
                TwoFactorIncomplete::deleteByUserAndDevice($login->user_uuid, $login->device_uuid);
            } catch (ApiError $error) {
                logger()->error("Error deleting incomplete 2FA record: {$error->getMessage()}");
            }
        }
    }

    /**
     * emergency_request_timeout_job (src/api/core/emergency_access.rs): grants
     * the requests whose wait time has passed.
     */
    public static function emergencyRequestTimeoutJob(): void
    {
        $config = app(Config::class);
        if (! $config->bool('emergency_access_allowed')) {
            return;
        }

        $now = Dates::now();
        foreach (EmergencyAccess::findAllRecoveriesInitiated() as $access) {
            try {
                if (! $access->isRecoveryAllowed($now)) {
                    continue;
                }
            } catch (ApiError $error) {
                logger()->error("Failed to check if recovery is allowed for '{$access->uuid}': {$error->getMessage()}");

                continue;
            }

            $users = self::grantorAndGrantee($access);
            if ($users === null) {
                continue;
            }
            [$grantor, $grantee] = $users;

            // Only the status, in case the reminder job saves the same access at the same time.
            try {
                $access->updateAccessStatusAndSave(EmergencyAccess::STATUS_RECOVERY_INITIATED, EmergencyAccess::STATUS_RECOVERY_APPROVED, $now);
            } catch (ApiError $error) {
                logger()->error("Unable to update emergency access status for '{$access->uuid}': {$error->getMessage()}");

                continue;
            }

            if ($config->mailEnabled()) {
                try {
                    Mail::sendEmergencyAccessRecoveryTimedOut($grantor->email, $grantee->name, $access->typeAsString());
                } catch (ApiError $error) {
                    logger()->error("Failed to send emergency access recovery time-out email for '{$access->uuid}': {$error->getMessage()}");
                }
                try {
                    Mail::sendEmergencyAccessRecoveryApproved($grantee->email, $grantor->name);
                } catch (ApiError $error) {
                    logger()->error("Failed to send emergency access recovery approved email for '{$access->uuid}': {$error->getMessage()}");
                }
            }
        }
    }

    /**
     * emergency_notification_reminder_job (src/api/core/emergency_access.rs):
     * reminds grantors once during the last day before a request is granted.
     */
    public static function emergencyNotificationReminderJob(): void
    {
        $config = app(Config::class);
        if (! $config->bool('emergency_access_allowed')) {
            return;
        }

        $now = Dates::now();
        foreach (EmergencyAccess::findAllRecoveriesInitiated() as $access) {
            try {
                $deadline = $access->deadlineAt();
            } catch (ApiError) {
                logger()->error("Invalid deadline for emergency access '{$access->uuid}'");

                continue;
            }

            if ($now < $deadline->modify('-1 day') || $now >= $deadline) {
                continue;
            }
            // Without a previous reminder, or when its date is off, remind now: one reminder too many beats none.
            if ($access->last_notification_at !== null && $access->last_notification_at->modify('+1 day') > $now) {
                continue;
            }

            $users = self::grantorAndGrantee($access);
            if ($users === null) {
                continue;
            }
            [$grantor, $grantee] = $users;

            // Only the date, in case the timeout job saves the same access at the same time.
            try {
                $access->updateLastNotificationDateAndSave($now);
            } catch (ApiError $error) {
                logger()->error("Unable to update emergency access reminder date for '{$access->uuid}': {$error->getMessage()}");

                continue;
            }

            if ($config->mailEnabled()) {
                try {
                    // Sent only on the last day.
                    Mail::sendEmergencyAccessRecoveryReminder($grantor->email, $grantee->name, $access->typeAsString(), '1');
                } catch (ApiError $error) {
                    logger()->error("Failed to send emergency access reminder email for '{$access->uuid}': {$error->getMessage()}");
                }
            }
        }
    }

    /**
     * purge_auth_requests (src/api/core/accounts.rs)
     */
    public static function purgeAuthRequests(): void
    {
        AuthRequest::purgeExpiredAuthRequests();
    }

    /**
     * purge_duo_contexts (src/api/core/two_factor/duo_oidc.rs)
     */
    public static function purgeDuoContexts(): void
    {
        TwoFactorDuoContext::purgeExpired();
    }

    /**
     * event_cleanup_job (src/api/core/events.rs)
     */
    public static function eventCleanupJob(): void
    {
        if (in_array(app(Config::class)->optionalInt('events_days_retain'), [null, 0], true)) {
            return;
        }

        try {
            Event::cleanEvents();
        } catch (ApiError $error) {
            logger()->error("Failed to cleanup the events table: {$error->getMessage()}");
        }
    }

    /**
     * SsoAuth::delete_expired (src/db/models/sso_auth.rs)
     */
    public static function purgeIncompleteSsoAuth(): void
    {
        try {
            SsoAuth::deleteExpired();
        } catch (ApiError $error) {
            logger()->error($error->getMessage());
        }
    }

    /**
     * @return array{User, User}|null
     */
    private static function grantorAndGrantee(EmergencyAccess $access): ?array
    {
        $grantor = User::findByUuid($access->grantor_uuid);
        if ($grantor === null) {
            logger()->error("Grantor user '{$access->grantor_uuid}' not found for emergency access '{$access->uuid}'");

            return null;
        }
        if ($access->grantee_uuid === null) {
            logger()->error("Missing grantee for emergency access '{$access->uuid}'");

            return null;
        }
        $grantee = User::findByUuid($access->grantee_uuid);
        if ($grantee === null) {
            logger()->error("Grantee user '{$access->grantee_uuid}' not found for emergency access '{$access->uuid}'");

            return null;
        }

        return [$grantor, $grantee];
    }
}
