<?php

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Rocket's default catcher (rocket 0.5): an HTML page, or JSON when the
 * preferred media type of the Accept header is JSON.
 */
final class DefaultCatcher
{
    /**
     * Reason phrase and description of each status Rocket knows.
     */
    private const array STATUSES = [
        400 => ['Bad Request', 'The request could not be understood by the server due to malformed syntax.'],
        401 => ['Unauthorized', 'The request requires user authentication.'],
        402 => ['Payment Required', 'The request could not be processed due to lack of payment.'],
        403 => ['Forbidden', 'The server refused to authorize the request.'],
        404 => ['Not Found', 'The requested resource could not be found.'],
        405 => ['Method Not Allowed', 'The request method is not supported for the requested resource.'],
        406 => ['Not Acceptable', 'The requested resource is capable of generating only content not acceptable according to the Accept headers sent in the request.'],
        407 => ['Proxy Authentication Required', 'Authentication with the proxy is required.'],
        408 => ['Request Timeout', 'The server timed out waiting for the request.'],
        409 => ['Conflict', 'The request could not be processed because of a conflict in the request.'],
        410 => ['Gone', 'The resource requested is no longer available and will not be available again.'],
        411 => ['Length Required', 'The request did not specify the length of its content, which is required by the requested resource.'],
        412 => ['Precondition Failed', 'The server does not meet one of the preconditions specified in the request.'],
        413 => ['Payload Too Large', 'The request is larger than the server is willing or able to process.'],
        414 => ['URI Too Long', 'The URI provided was too long for the server to process.'],
        415 => ['Unsupported Media Type', 'The request entity has a media type which the server or resource does not support.'],
        416 => ['Range Not Satisfiable', 'The portion of the requested file cannot be supplied by the server.'],
        417 => ['Expectation Failed', 'The server cannot meet the requirements of the expect request-header field.'],
        418 => ["I'm a teapot", 'I was requested to brew coffee, and I am a teapot.'],
        421 => ['Misdirected Request', 'The server cannot produce a response for this request.'],
        422 => ['Unprocessable Entity', 'The request was well-formed but was unable to be followed due to semantic errors.'],
        426 => ['Upgrade Required', 'Switching to the protocol in the Upgrade header field is required.'],
        428 => ['Precondition Required', 'The server requires the request to be conditional.'],
        429 => ['Too Many Requests', 'Too many requests have been received recently.'],
        431 => ['Request Header Fields Too Large', 'The server is unwilling to process the request because either an individual header field, or all the header fields collectively, are too large.'],
        451 => ['Unavailable For Legal Reasons', 'The requested resource is unavailable due to a legal demand to deny access to this resource.'],
        500 => ['Internal Server Error', 'The server encountered an internal error while processing this request.'],
        501 => ['Not Implemented', 'The server either does not recognize the request method, or it lacks the ability to fulfill the request.'],
        503 => ['Service Unavailable', 'The server is currently unavailable.'],
        504 => ['Gateway Timeout', 'The server did not receive a timely response from an upstream server.'],
        510 => ['Not Extended', 'Further extensions to the request are required for the server to fulfill them.'],
    ];

    public static function response(Request $request, int $status): Response
    {
        [$reason, $description] = self::STATUSES[$status] ?? ['Unknown Error', 'An unknown error has occurred.'];

        if (Accept::prefersJson($request)) {
            $json = "{\n  \"error\": {\n    \"code\": {$status},\n    \"reason\": \"{$reason}\",\n    \"description\": \"{$description}\"\n  }\n}";

            return new Response($json, $status, ['Content-Type' => 'application/json']);
        }

        $html = <<<HTML
            <!DOCTYPE html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="color-scheme" content="light dark">
                <title>{$status} {$reason}</title>
            </head>
            <body align="center">
                <div role="main" align="center">
                    <h1>{$status}: {$reason}</h1>
                    <p>{$description}</p>
                    <hr />
                </div>
                <div role="contentinfo" align="center">
                    <small>Rocket</small>
                </div>
            </body>
            </html>
            HTML;

        return new Response($html, $status, ['Content-Type' => 'text/html; charset=utf-8']);
    }
}
