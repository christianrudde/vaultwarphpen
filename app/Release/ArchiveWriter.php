<?php

namespace App\Release;

use DeflateContext;
use FilesystemIterator;
use HashContext;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use SplFileInfo;

/**
 * Packs a folder into a tar.gz and a zip archive below one top-level folder,
 * so that the same files give the same bytes wherever they are packed:
 * entries sorted by path, one timestamp for all of them, no owners, modes
 * reduced to 0755 for folders and executables and 0644 for everything else,
 * and the gzip header without a time or a host system (like `gzip -n`).
 */
final readonly class ArchiveWriter
{
    private const int CHUNK = 1 << 20;

    /**
     * @param  string  $folder  The folder to pack
     * @param  string  $prefix  The top-level folder in the archive
     * @param  int  $timestamp  The modification time of every entry
     */
    public function __construct(
        private string $folder,
        private string $prefix,
        private int $timestamp,
    ) {}

    /**
     * The entries in the order they are packed.
     *
     * @return list<array{name: string, path: string, directory: bool, mode: int}>
     */
    public function entries(): array
    {
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->folder, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        $entries = [['name' => "{$this->prefix}/", 'path' => $this->folder, 'directory' => true, 'mode' => 0755]];
        foreach ($files as $file) {
            /** @var SplFileInfo $file */
            $relative = substr($file->getPathname(), strlen(rtrim($this->folder, '/')) + 1);
            if ($file->isLink()) {
                throw new RuntimeException("{$relative} is a symbolic link, which the archives cannot hold.");
            }
            $directory = $file->isDir();
            $entries[] = [
                'name' => "{$this->prefix}/{$relative}".($directory ? '/' : ''),
                'path' => $file->getPathname(),
                'directory' => $directory,
                'mode' => $directory || ($file->getPerms() & 0100) !== 0 ? 0755 : 0644,
            ];
        }
        usort($entries, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        return $entries;
    }

    public function writeTarGz(string $file): void
    {
        $output = self::open($file);
        $deflate = self::deflate();
        $crc = hash_init('crc32b');
        $length = 0;
        $write = static function (string $data) use ($output, $deflate, $crc, &$length): void {
            hash_update($crc, $data);
            $length += strlen($data);
            self::write($output, (string) deflate_add($deflate, $data, ZLIB_NO_FLUSH));
        };

        try {
            // The gzip header: deflate, no flags, no time, maximum compression, Unix.
            self::write($output, "\x1f\x8b\x08\x00\x00\x00\x00\x00\x02\x03");
            foreach ($this->entries() as $entry) {
                $size = $entry['directory'] ? 0 : self::size($entry['path']);
                $write($this->tarHeader($entry['name'], $entry['mode'], $size, $entry['directory']));
                if (! $entry['directory']) {
                    self::read($entry['path'], $write);
                    $write(str_repeat("\0", (512 - $size % 512) % 512));
                }
            }
            $write(str_repeat("\0", 1024));
            self::write($output, (string) deflate_add($deflate, '', ZLIB_FINISH));
            self::write($output, pack('V', self::crc($crc)).pack('V', $length & 0xFFFFFFFF));
        } finally {
            fclose($output);
        }
    }

    public function writeZip(string $file): void
    {
        $output = self::open($file);
        [$time, $date] = $this->dosTime();
        // The extended timestamp, as Info-ZIP writes it: MS-DOS times are local and coarse.
        $extra = pack('vvCV', 0x5455, 5, 1, $this->timestamp);
        $central = '';
        $offset = 0;
        $entries = $this->entries();
        if (count($entries) > 0xFFFF) {
            throw new RuntimeException('Too many files for a zip archive without Zip64.');
        }

        try {
            foreach ($entries as $entry) {
                // Deflate into a temporary stream first: the local header comes before the data.
                $data = fopen('php://temp/maxmemory:'.(16 * self::CHUNK), 'w+b');
                if ($data === false) {
                    throw new RuntimeException('Cannot open a temporary stream.');
                }
                try {
                    $crc = hash_init('crc32b');
                    $size = 0;
                    if (! $entry['directory']) {
                        $deflate = self::deflate();
                        self::read($entry['path'], static function (string $chunk) use ($data, $deflate, $crc, &$size): void {
                            hash_update($crc, $chunk);
                            $size += strlen($chunk);
                            self::write($data, (string) deflate_add($deflate, $chunk, ZLIB_NO_FLUSH));
                        });
                        self::write($data, (string) deflate_add($deflate, '', ZLIB_FINISH));
                    }
                    $compressed = (int) ftell($data);
                    if ($size > 0xFFFFFFFE || $compressed > 0xFFFFFFFE || $offset > 0xFFFFFFFE) {
                        throw new RuntimeException("{$entry['name']} is too large for a zip archive without Zip64.");
                    }

                    // Version 2.0, file names in UTF-8, deflate (stored for folders).
                    $fields = pack('vvvvvVVVv', 20, 0x0800, $entry['directory'] ? 0 : 8, $time, $date, self::crc($crc), $compressed, $size, strlen($entry['name']));
                    $local = pack('V', 0x04034B50).$fields.pack('v', strlen($extra)).$entry['name'].$extra;
                    $type = $entry['directory'] ? 0040000 : 0100000;
                    // Made by Unix with version 2.0, so that unzip takes the mode from the upper half of the attributes.
                    $central .= pack('Vv', 0x02014B50, 0x0314).$fields
                        .pack('vvvvVV', strlen($extra), 0, 0, 0, (($type | $entry['mode']) << 16) | ($entry['directory'] ? 0x10 : 0), $offset)
                        .$entry['name'].$extra;

                    self::write($output, $local);
                    rewind($data);
                    if (stream_copy_to_stream($data, $output) !== $compressed) {
                        throw new RuntimeException("Cannot write {$file}.");
                    }
                    $offset += strlen($local) + $compressed;
                } finally {
                    fclose($data);
                }
            }

            self::write($output, $central);
            self::write($output, pack('VvvvvVVv', 0x06054B50, 0, 0, count($entries), count($entries), strlen($central), $offset, 0));
        } finally {
            fclose($output);
        }
    }

    /**
     * A ustar header. Names longer than 100 bytes are split at a slash into
     * the prefix field, which every tar understands.
     */
    private function tarHeader(string $name, int $mode, int $size, bool $directory): string
    {
        $prefix = '';
        if (strlen($name) > 100) {
            $slash = strpos($name, '/', max(0, strlen($name) - 101));
            if ($slash === false || $slash > 155 || strlen($name) - $slash - 1 > 100 || $slash === strlen($name) - 1) {
                throw new RuntimeException("{$name} is too long for a tar archive.");
            }
            $prefix = substr($name, 0, $slash);
            $name = substr($name, $slash + 1);
        }

        $header = str_pad($name, 100, "\0")
            .sprintf('%07o', $mode)."\0"
            ."0000000\0"
            ."0000000\0"
            .sprintf('%011o', $size)."\0"
            .sprintf('%011o', $this->timestamp)."\0"
            .'        '
            .($directory ? '5' : '0')
            .str_repeat("\0", 100)
            ."ustar\0".'00'
            .str_repeat("\0", 64)
            ."0000000\0"
            ."0000000\0"
            .str_pad($prefix, 155, "\0")
            .str_repeat("\0", 12);

        $checksum = array_sum((array) unpack('C*', $header));

        return substr_replace($header, sprintf('%06o', $checksum)."\0 ", 148, 8);
    }

    /**
     * The time of the entries as MS-DOS time and date, in UTC.
     *
     * @return array{int, int}
     */
    private function dosTime(): array
    {
        $part = fn (string $format): int => (int) gmdate($format, $this->timestamp);
        if ($part('Y') < 1980) {
            throw new RuntimeException('A zip archive cannot hold times before 1980.');
        }

        return [
            ($part('G') << 11) | ($part('i') << 5) | intdiv($part('s'), 2),
            (($part('Y') - 1980) << 9) | ($part('n') << 5) | $part('j'),
        ];
    }

    /**
     * @return resource
     */
    private static function open(string $file)
    {
        $output = @fopen($file, 'wb');
        if ($output === false) {
            throw new RuntimeException("Cannot write {$file}.");
        }

        return $output;
    }

    private static function deflate(): DeflateContext
    {
        $deflate = deflate_init(ZLIB_ENCODING_RAW, ['level' => 9]);
        if ($deflate === false) {
            throw new RuntimeException('Cannot start deflating.');
        }

        return $deflate;
    }

    /**
     * @param  callable(string): void  $consume
     */
    private static function read(string $path, callable $consume): void
    {
        $input = @fopen($path, 'rb');
        if ($input === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }
        try {
            while (! feof($input)) {
                $chunk = fread($input, self::CHUNK);
                if ($chunk === false) {
                    throw new RuntimeException("Cannot read {$path}.");
                }
                if ($chunk !== '') {
                    $consume($chunk);
                }
            }
        } finally {
            fclose($input);
        }
    }

    /**
     * @param  resource  $output
     */
    private static function write($output, string $data): void
    {
        if ($data !== '' && fwrite($output, $data) !== strlen($data)) {
            throw new RuntimeException('Cannot write the archive.');
        }
    }

    private static function size(string $path): int
    {
        $size = filesize($path);
        if ($size === false) {
            throw new RuntimeException("Cannot read {$path}.");
        }

        return $size;
    }

    private static function crc(HashContext $crc): int
    {
        return (int) hexdec(hash_final($crc));
    }
}
