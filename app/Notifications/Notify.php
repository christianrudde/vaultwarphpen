<?php

namespace App\Notifications;

use App\Config\Config;
use App\Models\Cipher;
use App\Models\Device;
use App\Models\Folder;
use App\Models\Send;
use App\Models\User;
use App\Support\Dates;
use App\Support\Push;
use App\Support\UpdateType;

/**
 * WebSocketUsers and AnonymousWebSocketSubscriptions of
 * src/api/notifications.rs, on the side of the requests (api::Notify and
 * api::AnonymousNotify): the updates go to the open hub connections through
 * the notifications daemon and, with PUSH_ENABLED, to the push relay.
 *
 * The last modified date needs to be updated before calling these methods.
 */
final readonly class Notify
{
    public function __construct(
        private Config $config,
        private Channel $channel,
    ) {}

    /**
     * Skip any processing if both WebSockets and Push are not active.
     */
    private function disabled(): bool
    {
        return ! $this->config->bool('enable_websocket') && ! $this->config->bool('push_enabled');
    }

    public function sendUserUpdate(UpdateType $type, User $user, ?string $pushUuid): void
    {
        if ($this->disabled()) {
            return;
        }
        $data = SignalR::update(['UserId' => $user->uuid, 'Date' => SignalR::date($user->updated_at)], $type, null);

        if ($this->config->bool('enable_websocket')) {
            $this->channel->toUsers([$user->uuid], $data);
        }

        if ($this->config->bool('push_enabled')) {
            Push::userUpdate($type, $user, $pushUuid);
        }
    }

    public function sendLogout(User $user, ?Device $actingDevice): void
    {
        if ($this->disabled()) {
            return;
        }
        $data = SignalR::update(['UserId' => $user->uuid, 'Date' => SignalR::date($user->updated_at)], UpdateType::LogOut, $actingDevice?->uuid);

        if ($this->config->bool('enable_websocket')) {
            $this->channel->toUsers([$user->uuid], $data);
        }

        if ($this->config->bool('push_enabled')) {
            Push::logout($user, $actingDevice);
        }
    }

    public function sendFolderUpdate(UpdateType $type, Folder $folder, Device $device): void
    {
        if ($this->disabled()) {
            return;
        }
        $data = SignalR::update([
            'Id' => $folder->uuid,
            'UserId' => $folder->user_uuid,
            'RevisionDate' => SignalR::date($folder->updated_at),
        ], $type, $device->uuid);

        if ($this->config->bool('enable_websocket')) {
            $this->channel->toUsers([$folder->user_uuid], $data);
        }

        if ($this->config->bool('push_enabled')) {
            Push::folderUpdate($type, $folder, $device);
        }
    }

    /**
     * With collections, the user is null and the revision date is now, else
     * the clients won't sync the collection change.
     *
     * @param  list<string>  $userIds
     * @param  list<string>|null  $collectionUuids
     */
    public function sendCipherUpdate(UpdateType $type, Cipher $cipher, array $userIds, Device $device, ?array $collectionUuids = null): void
    {
        if ($this->disabled()) {
            return;
        }
        $data = SignalR::update([
            'Id' => $cipher->uuid,
            'UserId' => $collectionUuids === null ? $cipher->user_uuid : null,
            'OrganizationId' => $cipher->organization_uuid,
            'CollectionIds' => $collectionUuids,
            'RevisionDate' => SignalR::date($collectionUuids === null ? $cipher->updated_at : Dates::now()),
        ], $type, $device->uuid);

        if ($this->config->bool('enable_websocket')) {
            $this->channel->toUsers($userIds, $data);
        }

        if ($this->config->bool('push_enabled') && count($userIds) === 1) {
            Push::cipherUpdate($type, $cipher, $device);
        }
    }

    /**
     * @param  list<string>  $userIds
     */
    public function sendSendUpdate(UpdateType $type, Send $send, array $userIds, Device $device): void
    {
        if ($this->disabled()) {
            return;
        }
        $data = SignalR::update([
            'Id' => $send->uuid,
            'UserId' => $send->user_uuid,
            'RevisionDate' => SignalR::date($send->revision_date),
        ], $type, null);

        if ($this->config->bool('enable_websocket')) {
            $this->channel->toUsers($userIds, $data);
        }
        if ($this->config->bool('push_enabled') && count($userIds) === 1) {
            Push::sendUpdate($type, $send, $device);
        }
    }

    public function sendAuthRequest(string $userId, string $authRequestUuid, Device $device): void
    {
        if ($this->disabled()) {
            return;
        }
        $data = SignalR::update(['Id' => $authRequestUuid, 'UserId' => $userId], UpdateType::AuthRequest, $device->uuid);
        if ($this->config->bool('enable_websocket')) {
            $this->channel->toUsers([$userId], $data);
        }

        if ($this->config->bool('push_enabled')) {
            Push::authRequest($userId, $authRequestUuid, $device);
        }
    }

    public function sendAuthResponse(string $userId, string $authRequestId, Device $device): void
    {
        if ($this->disabled()) {
            return;
        }
        $data = SignalR::update(['Id' => $authRequestId, 'UserId' => $userId], UpdateType::AuthRequestResponse, $device->uuid);
        if ($this->config->bool('enable_websocket')) {
            $this->channel->toUsers([$userId], $data);
        }

        if ($this->config->bool('push_enabled')) {
            Push::authResponse($userId, $authRequestId, $device);
        }
    }

    /**
     * AnonymousWebSocketSubscriptions::send_auth_response: to whoever waits
     * on the anonymous hub with the auth request's id.
     */
    public function sendAnonymousAuthResponse(string $userId, string $authRequestId): void
    {
        if (! $this->config->bool('enable_websocket')) {
            return;
        }
        $data = SignalR::anonymousUpdate(['Id' => $authRequestId, 'UserId' => $userId], UpdateType::AuthRequestResponse, $userId);
        $this->channel->toAnonymous($authRequestId, $data);
    }
}
