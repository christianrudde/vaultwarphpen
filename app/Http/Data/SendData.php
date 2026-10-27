<?php

namespace App\Http\Data;

use App\Http\HttpStatus;
use App\Support\Dates;
use App\Support\JsonValue;
use DateTimeImmutable;

/**
 * SendData in src/api/core/sends.rs.
 */
final readonly class SendData
{
    public function __construct(
        public int $type,
        public string $key,
        public ?string $password,
        public int|string|null $maxAccessCount,
        public ?DateTimeImmutable $expirationDate,
        public DateTimeImmutable $deletionDate,
        public bool $disabled,
        public ?bool $hideEmail,
        public ?string $emails,
        public string $name,
        public ?string $notes,
        public mixed $text,
        public mixed $file,
        public int|string|null $fileLength,
        public ?string $id,
    ) {}

    /**
     * @throws HttpStatus
     */
    public static function fromFields(Fields $fields): self
    {
        $expiration = $fields->optionalString('expirationDate');

        return new self(
            type: $fields->int('type'),
            key: $fields->string('key'),
            password: $fields->optionalString('password'),
            maxAccessCount: NumberOrString::optional($fields, 'maxAccessCount'),
            expirationDate: $expiration === null ? null : self::date($expiration, 'expirationDate'),
            deletionDate: self::date($fields->string('deletionDate'), 'deletionDate'),
            disabled: $fields->bool('disabled'),
            hideEmail: $fields->optionalBool('hideEmail'),
            emails: $fields->optionalString('emails'),
            name: $fields->string('name'),
            notes: $fields->optionalString('notes'),
            text: JsonValue::toArrays($fields->value('text')),
            file: JsonValue::toArrays($fields->value('file')),
            fileLength: NumberOrString::optional($fields, 'fileLength'),
            id: $fields->optionalString('id'),
        );
    }

    /**
     * A DateTime<Utc>, which fails the whole body when it does not parse.
     *
     * @throws HttpStatus
     */
    private static function date(string $value, string $name): DateTimeImmutable
    {
        return Dates::parseUtc($value) ?? throw new HttpStatus(422, "Field `{$name}` is not a date.");
    }
}
