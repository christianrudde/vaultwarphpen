<?php

/**
 * A minimal SMTP server for the differential and client tests: it accepts every mail
 * and writes it, as received, to <dir>/<sequence>.eml. No TLS, no auth.
 *
 * Usage: php smtp-sink.php <port> <dir>
 */
[, $port, $dir] = $argv + [null, null, null];
if (! is_string($port) || ! is_string($dir)) {
    fwrite(STDERR, "Usage: php smtp-sink.php <port> <dir>\n");
    exit(2);
}
if (! is_dir($dir)) {
    mkdir($dir, 0777, true);
}

$server = stream_socket_server("tcp://127.0.0.1:{$port}", $errno, $error);
if ($server === false) {
    fwrite(STDERR, "Cannot listen on {$port}: {$error}\n");
    exit(1);
}

$sequence = 0;
// Per connection: its buffer of unread input and the message being received, if any.
$clients = [];

while (true) {
    $read = [$server, ...array_column($clients, 'socket')];
    $write = $except = null;
    if (stream_select($read, $write, $except, null) === false) {
        break;
    }

    foreach ($read as $socket) {
        if ($socket === $server) {
            $client = stream_socket_accept($server);
            if ($client !== false) {
                $clients[(int) $client] = ['socket' => $client, 'buffer' => '', 'data' => null];
                fwrite($client, "220 sink ESMTP\r\n");
            }

            continue;
        }

        $id = (int) $socket;
        $chunk = fread($socket, 65536);
        if ($chunk === false || ($chunk === '' && feof($socket))) {
            fclose($socket);
            unset($clients[$id]);

            continue;
        }
        $clients[$id]['buffer'] .= $chunk;

        while (($end = strpos($clients[$id]['buffer'], "\n")) !== false) {
            $line = substr($clients[$id]['buffer'], 0, $end + 1);
            $clients[$id]['buffer'] = substr($clients[$id]['buffer'], $end + 1);

            if ($clients[$id]['data'] !== null) {
                if (rtrim($line, "\r\n") === '.') {
                    file_put_contents(sprintf('%s/%06d.eml', $dir, ++$sequence), $clients[$id]['data']);
                    $clients[$id]['data'] = null;
                    fwrite($socket, "250 OK queued\r\n");
                } else {
                    // Dot stuffing.
                    $clients[$id]['data'] .= str_starts_with($line, '..') ? substr($line, 1) : $line;
                }

                continue;
            }

            switch (strtoupper(substr(trim($line), 0, 4))) {
                case 'EHLO':
                    fwrite($socket, "250-sink\r\n250-8BITMIME\r\n250-SMTPUTF8\r\n250 PIPELINING\r\n");
                    break;
                case 'HELO':
                    fwrite($socket, "250 sink\r\n");
                    break;
                case 'DATA':
                    $clients[$id]['data'] = '';
                    fwrite($socket, "354 End data with <CR><LF>.<CR><LF>\r\n");
                    break;
                case 'QUIT':
                    fwrite($socket, "221 Bye\r\n");
                    fclose($socket);
                    unset($clients[$id]);
                    break 2;
                default:
                    // MAIL, RCPT, RSET, NOOP
                    fwrite($socket, "250 OK\r\n");
            }
        }
    }
}
