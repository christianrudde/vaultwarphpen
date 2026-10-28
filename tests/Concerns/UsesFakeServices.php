<?php

namespace Tests\Concerns;

use RuntimeException;

/**
 * The fake services of the differential tests (.github/scripts/fake-services.php),
 * started over HTTPS for the test class with a certificate authority of its
 * own, which SSL_CERT_FILE makes the application trust.
 */
trait UsesFakeServices
{
    /**
     * @var resource|null
     */
    private static mixed $servicesProcess = null;

    private static string $servicesFolder = '';

    // Host and port.
    private static string $servicesHost = '';

    public static function setUpBeforeClass(): void
    {
        self::$servicesFolder = sys_get_temp_dir().'/vw-services-'.bin2hex(random_bytes(6));
        mkdir(self::$servicesFolder);
        $folder = escapeshellarg(self::$servicesFolder);
        exec("cd {$folder} && openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=Test CA' -addext 'basicConstraints=critical,CA:TRUE'"
            ." -addext 'keyUsage=critical,keyCertSign' -keyout ca.key -out ca.pem 2>&1"
            ." && openssl req -newkey rsa:2048 -nodes -subj '/CN=127.0.0.1' -keyout services.key -out services.csr 2>&1"
            ." && printf 'subjectAltName=IP:127.0.0.1\\nbasicConstraints=critical,CA:FALSE\\nextendedKeyUsage=serverAuth\\n' > services.ext"
            .' && openssl x509 -req -in services.csr -CA ca.pem -CAkey ca.key -CAcreateserial -days 1 -extfile services.ext -out services.pem 2>&1', $output, $status);
        if ($status !== 0) {
            throw new RuntimeException('Cannot create the certificates: '.implode("\n", $output));
        }

        // A free port, taken right after it is found.
        $probe = stream_socket_server('tcp://127.0.0.1:0');
        $address = $probe === false ? '' : (string) stream_socket_get_name($probe, false);
        if ($probe !== false) {
            fclose($probe);
        }
        $port = substr($address, strrpos($address, ':') + 1);
        self::$servicesHost = "127.0.0.1:{$port}";

        $script = dirname(__DIR__, 2).'/.github/scripts/fake-services.php';
        $process = proc_open([PHP_BINARY, $script, $port, self::$servicesFolder.'/services.pem', self::$servicesFolder.'/services.key', self::$servicesFolder.'/state'],
            [1 => ['file', '/dev/null', 'w'], 2 => ['file', self::$servicesFolder.'/services.log', 'w']], $pipes);
        self::$servicesProcess = $process === false ? null : $process;

        for ($attempt = 0; $attempt < 50; $attempt++) {
            if (@stream_socket_client('tls://'.self::$servicesHost, $errno, $error, 1, STREAM_CLIENT_CONNECT, self::servicesContext()) !== false) {
                return;
            }
            usleep(100_000);
        }

        throw new RuntimeException('The fake services did not start: '.file_get_contents(self::$servicesFolder.'/services.log'));
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$servicesProcess !== null) {
            proc_terminate(self::$servicesProcess);
            proc_close(self::$servicesProcess);
        }
        exec('rm -rf '.escapeshellarg(self::$servicesFolder));
    }

    /**
     * A GET from the fake services, such as what a fake relay recorded.
     */
    protected static function fromServices(string $path): string
    {
        return (string) file_get_contents('https://'.self::$servicesHost.$path, false, self::servicesContext());
    }

    /**
     * @return resource
     */
    private static function servicesContext(): mixed
    {
        return stream_context_create(['ssl' => ['cafile' => self::$servicesFolder.'/ca.pem']]);
    }
}
