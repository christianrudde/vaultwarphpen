<?php

/**
 * Stand-ins for the Yubico validation servers and Duo's API, for the
 * differential tests: an HTTPS server that answers one request per
 * connection. The servers under test trust its certificate authority
 * through SSL_CERT_FILE.
 *
 * Usage: php fake-services.php <port> <certificate> <key> <state dir>
 *
 * Yubico (Validation Protocol 2.0, GET /wsapi/2.0/verify): the request must be
 * signed with the API key in YUBICO_SECRET_KEY, and so is the answer. Every
 * OTP is fine, except those ending in "vvvv" (BAD_OTP) or "rrrr"
 * (REPLAYED_OTP); answers to OTPs ending in "nnnn" carry another nonce and
 * those ending in "ssss" a wrong signature.
 *
 * Duo: the Auth API check and the OIDC endpoints of the Universal Prompt.
 * The secret of a client id is duoSecret(), which the tests share. The
 * authorization endpoint lets everyone in at once: it redirects to the
 * client with a code, which the token endpoint exchanges once.
 */
[, $port, $certificate, $key, $state] = $argv + [null, null, null, null, null];
if (! is_string($port) || ! is_string($certificate) || ! is_string($key) || ! is_string($state)) {
    fwrite(STDERR, "Usage: php fake-services.php <port> <certificate> <key> <state dir>\n");
    exit(2);
}
if (! is_dir($state)) {
    mkdir($state, 0777, true);
}

$context = stream_context_create(['ssl' => ['local_cert' => $certificate, 'local_pk' => $key, 'verify_peer' => false]]);
$server = stream_socket_server("tls://127.0.0.1:{$port}", $errno, $error, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN, $context);
if ($server === false) {
    fwrite(STDERR, "Cannot listen on {$port}: {$error}\n");
    exit(1);
}

while (true) {
    // Clients that fail the handshake are dropped.
    $client = @stream_socket_accept($server, -1);
    if ($client === false) {
        continue;
    }
    stream_set_timeout($client, 10);

    $head = '';
    while (! str_contains($head, "\r\n\r\n") && ($line = fgets($client)) !== false) {
        $head .= $line;
    }
    $lines = explode("\r\n", trim($head));
    [$method, $target] = explode(' ', array_shift($lines) ?? '') + ['', ''];
    $headers = [];
    foreach ($lines as $line) {
        [$name, $value] = explode(':', $line, 2) + ['', ''];
        $headers[strtolower(trim($name))] = trim($value);
    }
    $body = '';
    $length = (int) ($headers['content-length'] ?? 0);
    while (strlen($body) < $length && ! feof($client)) {
        $body .= (string) fread($client, $length - strlen($body));
    }

    [$status, $type, $content, $extra] = respond($method, $target, $headers, $body, $state) + [3 => []];
    $reasons = [200 => 'OK', 302 => 'Found', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found'];
    $response = "HTTP/1.1 {$status} ".($reasons[$status] ?? 'Error')."\r\nContent-Type: {$type}\r\nContent-Length: ".strlen($content)."\r\nConnection: close\r\n";
    foreach ($extra as $name => $value) {
        $response .= "{$name}: {$value}\r\n";
    }
    fwrite($client, $response."\r\n".$content);
    fclose($client);
}

/**
 * @param  array<string, string>  $headers
 * @return array{0: int, 1: string, 2: string, 3?: array<string, string>}
 */
function respond(string $method, string $target, array $headers, string $body, string $state): array
{
    $path = (string) parse_url($target, PHP_URL_PATH);
    parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
    parse_str($body, $form);
    $host = $headers['host'] ?? '';

    return match ("{$method} {$path}") {
        'GET /wsapi/2.0/verify' => yubico($target, $query),
        'GET /auth/v2/check' => duoCheck($headers, $host),
        'POST /oauth/v1/health_check' => duoHealthCheck($form, $host),
        'GET /oauth/v1/authorize' => duoAuthorize($query, $host, $state),
        'POST /oauth/v1/token' => duoToken($form, $host, $state),
        default => [404, 'text/plain', 'Not found'],
    };
}

/**
 * @param  array<mixed>  $query
 * @return array{int, string, string}
 */
function yubico(string $target, array $query): array
{
    $key = (string) base64_decode((string) getenv('YUBICO_SECRET_KEY'), true);
    // The request is signed over its other parameters, in the order sent.
    $unsigned = (string) preg_replace('/&h=[^&]*$/', '', (string) parse_url($target, PHP_URL_QUERY));
    $otp = is_string($query['otp'] ?? null) ? $query['otp'] : '';
    $nonce = is_string($query['nonce'] ?? null) ? $query['nonce'] : '';

    $status = match (true) {
        ! hash_equals(base64_encode(hash_hmac('sha1', $unsigned, $key, true)), is_string($query['h'] ?? null) ? $query['h'] : '') => 'BAD_SIGNATURE',
        str_ends_with($otp, 'vvvv') => 'BAD_OTP',
        str_ends_with($otp, 'rrrr') => 'REPLAYED_OTP',
        default => 'OK',
    };
    $fields = ['nonce' => str_ends_with($otp, 'nnnn') ? strrev($nonce) : $nonce, 'otp' => $otp, 'sl' => '100',
        'status' => $status, 't' => gmdate('Y-m-d\TH:i:s\Z0000')];
    $signed = [];
    foreach ($fields as $name => $value) {
        $signed[] = "{$name}={$value}";
    }
    $signature = str_ends_with($otp, 'ssss') ? str_repeat("\0", 20) : hash_hmac('sha1', implode('&', $signed), $key, true);

    $content = 'h='.base64_encode($signature)."\r\n";
    foreach ($fields as $name => $value) {
        $content .= "{$name}={$value}\r\n";
    }

    return [200, 'text/plain', $content."\r\n"];
}

/**
 * The secret Duo issued with a client id; the tests derive it the same way.
 */
function duoSecret(string $clientId): string
{
    return substr(hash('sha256', "duo:{$clientId}"), 0, 40);
}

/**
 * Auth API: HTTP Basic with the client id and the HMAC-SHA1 of the request.
 *
 * @param  array<string, string>  $headers
 * @return array{int, string, string}
 */
function duoCheck(array $headers, string $host): array
{
    [$id, $signature] = explode(':', (string) base64_decode(substr($headers['authorization'] ?? '', 6), true), 2) + ['', ''];
    $expected = hash_hmac('sha1', implode("\n", [$headers['date'] ?? '', 'GET', $host, '/auth/v2/check', '']), duoSecret($id));

    return hash_equals($expected, $signature)
        ? [200, 'application/json', '{"response":{"time":'.time().'},"stat":"OK"}']
        : [401, 'application/json', '{"code":40103,"message":"Invalid signature in request credentials","stat":"FAIL"}'];
}

/**
 * @param  array<mixed>  $form
 * @return array{int, string, string}
 */
function duoHealthCheck(array $form, string $host): array
{
    $clientId = is_string($form['client_id'] ?? null) ? $form['client_id'] : '';
    $assertion = jwtDecode(is_string($form['client_assertion'] ?? null) ? $form['client_assertion'] : '', duoSecret($clientId));
    if ($assertion === null || ($assertion['aud'] ?? null) !== "https://{$host}/oauth/v1/health_check") {
        return [400, 'application/json', '{"message":"invalid_client","message_detail":"The client assertion is invalid","stat":"FAIL","code":40002}'];
    }

    return [200, 'application/json', '{"response":{"timestamp":'.time().'},"stat":"OK"}'];
}

/**
 * @param  array<mixed>  $query
 * @return array{int, string, string, array<string, string>}
 */
function duoAuthorize(array $query, string $host, string $state): array
{
    $clientId = is_string($query['client_id'] ?? null) ? $query['client_id'] : '';
    $request = jwtDecode(is_string($query['request'] ?? null) ? $query['request'] : '', duoSecret($clientId));
    if ($request === null || ($request['aud'] ?? null) !== "https://{$host}" || ! is_string($request['redirect_uri'] ?? null)) {
        return [400, 'text/plain', 'Invalid request', []];
    }

    $code = bin2hex(random_bytes(16));
    file_put_contents("{$state}/{$code}.json", json_encode(['client_id' => $clientId, 'redirect_uri' => $request['redirect_uri'],
        'nonce' => $request['nonce'] ?? '', 'username' => $request['duo_uname'] ?? '']));
    $separator = str_contains($request['redirect_uri'], '?') ? '&' : '?';
    $state = is_string($request['state'] ?? null) ? $request['state'] : '';

    return [302, 'text/plain', '', ['Location' => $request['redirect_uri'].$separator.http_build_query(['state' => $state, 'duo_code' => $code])]];
}

/**
 * @param  array<mixed>  $form
 * @return array{int, string, string}
 */
function duoToken(array $form, string $host, string $state): array
{
    $assertion = is_string($form['client_assertion'] ?? null) ? $form['client_assertion'] : '';
    $payload = json_decode((string) base64_decode(strtr(explode('.', $assertion)[1] ?? '', '-_', '+/')), true);
    $clientId = is_array($payload) && is_string($payload['iss'] ?? null) ? $payload['iss'] : '';
    $tokenUrl = "https://{$host}/oauth/v1/token";
    $claims = jwtDecode($assertion, duoSecret($clientId));
    $code = is_string($form['code'] ?? null) ? $form['code'] : '';
    $file = "{$state}/".basename($code).'.json';
    $grant = $code !== '' && is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
    if (is_file($file)) {
        unlink($file);
    }

    if ($claims === null || ($claims['aud'] ?? null) !== $tokenUrl || ! is_array($grant) || $grant['client_id'] !== $clientId
        || ($form['redirect_uri'] ?? null) !== $grant['redirect_uri'] || ($form['grant_type'] ?? null) !== 'authorization_code') {
        return [400, 'application/json', '{"error":"invalid_grant","error_description":"The code is invalid"}'];
    }

    $now = time();
    $idToken = jwtEncode(['iss' => $tokenUrl, 'aud' => $clientId, 'exp' => $now + 300, 'iat' => $now,
        'preferred_username' => $grant['username'], 'nonce' => $grant['nonce']], duoSecret($clientId));

    return [200, 'application/json', (string) json_encode(['id_token' => $idToken, 'access_token' => bin2hex(random_bytes(16)),
        'expires_in' => 3600, 'token_type' => 'Bearer'], JSON_UNESCAPED_SLASHES)];
}

/**
 * @param  array<string, mixed>  $claims
 */
function jwtEncode(array $claims, string $secret): string
{
    $base64 = static fn (string $data): string => rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    $input = $base64('{"typ":"JWT","alg":"HS512"}').'.'.$base64((string) json_encode($claims, JSON_UNESCAPED_SLASHES));

    return $input.'.'.$base64(hash_hmac('sha512', $input, $secret, true));
}

/**
 * The claims of an HS512 token with a valid signature that has not expired.
 *
 * @return array<string, mixed>|null
 */
function jwtDecode(string $token, string $secret): ?array
{
    $parts = explode('.', $token);
    if (count($parts) !== 3) {
        return null;
    }
    $signature = rtrim(strtr(base64_encode(hash_hmac('sha512', "{$parts[0]}.{$parts[1]}", $secret, true)), '+/', '-_'), '=');
    $claims = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);
    if (! hash_equals($signature, $parts[2]) || ! is_array($claims) || ! is_int($claims['exp'] ?? null) || $claims['exp'] < time()) {
        return null;
    }

    return $claims;
}
