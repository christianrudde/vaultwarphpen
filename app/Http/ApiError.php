<?php

namespace App\Http;

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use stdClass;

/**
 * Vaultwarden's error type (src/error.rs) and the JSON it responds with,
 * by default with status 400.
 */
final class ApiError extends RuntimeException
{
    /**
     * @param  'simple'|'compact'|'empty'|'json'  $kind
     */
    private function __construct(
        string $message,
        public readonly int $status,
        public readonly string $kind,
        public readonly mixed $body = null,
    ) {
        parent::__construct($message);
    }

    /**
     * err!(message), err_code!(message, status) and errors converted with `?`,
     * whose message is the name of their kind ("Db", "Serde", ...).
     */
    public static function message(string $message, int $status = 400): self
    {
        return new self($message, $status, 'simple');
    }

    /**
     * err!(Compact, message): the shorter error model of newer endpoints.
     */
    public static function compact(string $message, int $status = 400): self
    {
        return new self($message, $status, 'compact');
    }

    /**
     * Error::empty(): an empty JSON object.
     */
    public static function empty(int $status = 400): self
    {
        return new self('', $status, 'empty');
    }

    /**
     * err_json!(value, log message): the value itself, for 2FA and similar responses.
     */
    public static function json(mixed $body, string $logMessage, int $status = 400): self
    {
        return new self($logMessage, $status, 'json', $body);
    }

    public function withStatus(int $status): self
    {
        return new self($this->message, $status, $this->kind, $this->body);
    }

    /**
     * err! logs the message, without a stack trace.
     */
    public function report(): void
    {
        Log::error($this->message);
    }

    public function toResponse(): Response
    {
        $message = $this->message;

        // ApiErrorResponse and CompactApiErrorResponse serialize their fields in this order.
        $body = match ($this->kind) {
            'simple' => [
                'message' => $message,
                'validationErrors' => ['' => [$message]],
                'errorModel' => ['message' => $message, 'object' => 'error'],
                'error' => '',
                'error_description' => '',
                'exceptionMessage' => null,
                'exceptionStackTrace' => null,
                'innerExceptionMessage' => null,
                'object' => 'error',
            ],
            'compact' => [
                'message' => $message,
                'validationErrors' => null,
                'exceptionMessage' => null,
                'exceptionStackTrace' => null,
                'innerExceptionMessage' => null,
                'object' => 'error',
            ],
            'empty' => new stdClass,
            default => $this->body,
        };

        return Json::response($body, $this->status);
    }
}
