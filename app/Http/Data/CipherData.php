<?php

namespace App\Http\Data;

use App\Http\HttpStatus;
use App\Support\JsonValue;

/**
 * CipherData in src/api/core/ciphers.rs.
 */
final class CipherData
{
    /**
     * @param  array<string, mixed>  $typeData  The JSON of each type key that was given (login, card, ...)
     * @param  array<string, array{fileName: string, key: string}>|null  $attachments2
     */
    public function __construct(
        public ?string $id,
        public ?string $folderId,
        public ?string $organizationId,
        public ?string $key,
        public string $encryptedFor,
        public ?string $encryptedByKeyId,
        public int $type,
        public string $name,
        public ?string $notes,
        public mixed $fields,
        public array $typeData,
        public ?bool $favorite,
        public ?int $reprompt,
        public mixed $passwordHistory,
        public ?array $attachments2,
        public ?string $lastKnownRevisionDate,
        public ?string $archivedDate,
    ) {}

    /**
     * @throws HttpStatus
     */
    public static function fromFields(Fields $fields): self
    {
        $typeData = [];
        foreach (['login', 'secureNote', 'card', 'identity', 'sshKey', 'bankAccount', 'driversLicense', 'passport'] as $key) {
            $value = $fields->value($key);
            if ($value !== null) {
                $typeData[$key] = JsonValue::toArrays($value);
            }
        }

        $attachments2 = null;
        $attachments = $fields->optionalObject('attachments2');
        if ($attachments !== null) {
            $attachments2 = [];
            $raw = $fields->value('attachments2');
            foreach (array_keys(is_object($raw) ? get_object_vars($raw) : []) as $id) {
                $attachment = $attachments->object((string) $id);
                $attachments2[(string) $id] = ['fileName' => $attachment->string('fileName'), 'key' => $attachment->string('key')];
            }
        }
        $fields->value('attachments');

        return new self(
            id: $fields->optionalString('id'),
            folderId: $fields->optionalNonEmptyString('folderId'),
            organizationId: $fields->optionalString('organizationId', 'organizationID'),
            key: $fields->optionalString('key'),
            encryptedFor: $fields->string('encryptedFor'),
            encryptedByKeyId: $fields->optionalString('encryptedByKeyId'),
            type: $fields->int('type'),
            name: $fields->string('name'),
            notes: $fields->optionalString('notes'),
            fields: JsonValue::toArrays($fields->value('fields')),
            typeData: $typeData,
            favorite: $fields->optionalBool('favorite'),
            reprompt: $fields->optionalInt('reprompt'),
            passwordHistory: JsonValue::toArrays($fields->value('passwordHistory')),
            attachments2: $attachments2,
            lastKnownRevisionDate: $fields->optionalString('lastKnownRevisionDate'),
            archivedDate: $fields->optionalString('archivedDate'),
        );
    }
}
