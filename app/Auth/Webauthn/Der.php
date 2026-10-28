<?php

namespace App\Auth\Webauthn;

/**
 * Just enough DER to build public keys for OpenSSL and to read what
 * webauthn-rs checks in attestation certificates.
 */
final class Der
{
    public static function sequence(string ...$items): string
    {
        return self::tlv(0x30, implode('', $items));
    }

    public static function integer(string $unsigned): string
    {
        $unsigned = ltrim($unsigned, "\0");
        if ($unsigned === '' || ord($unsigned[0]) >= 0x80) {
            $unsigned = "\0".$unsigned;
        }

        return self::tlv(0x02, $unsigned);
    }

    public static function bitString(string $bytes): string
    {
        return self::tlv(0x03, "\0".$bytes);
    }

    public static function null(): string
    {
        return "\x05\x00";
    }

    public static function oid(string $dotted): string
    {
        $parts = array_map(intval(...), explode('.', $dotted));
        $bytes = chr(($parts[0] * 40 + $parts[1]) & 0xFF);
        foreach (array_slice($parts, 2) as $part) {
            $chunk = chr($part & 0x7F);
            while (($part >>= 7) > 0) {
                $chunk = chr(0x80 | ($part & 0x7F)).$chunk;
            }
            $bytes .= $chunk;
        }

        return self::tlv(0x06, $bytes);
    }

    public static function tlv(int $tag, string $content): string
    {
        $length = strlen($content);
        if ($length < 0x80) {
            return chr($tag & 0xFF).chr($length).$content;
        }
        $bytes = ltrim(pack('N', $length), "\0");

        return chr($tag & 0xFF).chr((0x80 | strlen($bytes)) & 0xFF).$bytes.$content;
    }

    /**
     * A PEM block for OpenSSL.
     */
    public static function pem(string $der, string $label): string
    {
        return "-----BEGIN {$label}-----\n".chunk_split(base64_encode($der), 64, "\n")."-----END {$label}-----\n";
    }

    /**
     * The TLV items in the data: tag, content and the whole encoding of each.
     *
     * @return list<array{tag: int, content: string, raw: string}>|null Null when malformed
     */
    public static function items(string $data): ?array
    {
        $items = [];
        $offset = 0;
        $length = strlen($data);
        while ($offset < $length) {
            if ($offset + 2 > $length) {
                return null;
            }
            $tag = ord($data[$offset]);
            if (($tag & 0x1F) === 0x1F) {
                // High tag numbers do not occur in what we read.
                return null;
            }
            $size = ord($data[$offset + 1]);
            $header = 2;
            if ($size >= 0x80) {
                $count = $size & 0x7F;
                if ($count === 0 || $count > 4 || $offset + 2 + $count > $length) {
                    return null;
                }
                $size = 0;
                for ($i = 0; $i < $count; $i++) {
                    $size = ($size << 8) | ord($data[$offset + 2 + $i]);
                }
                $header += $count;
            }
            if ($offset + $header + $size > $length) {
                return null;
            }
            $items[] = ['tag' => $tag, 'content' => substr($data, $offset + $header, $size), 'raw' => substr($data, $offset, $header + $size)];
            $offset += $header + $size;
        }

        return $items;
    }

    /**
     * The content of the only item, when it has the tag.
     */
    public static function single(string $data, int $tag): ?string
    {
        $items = self::items($data);

        return $items !== null && count($items) === 1 && $items[0]['tag'] === $tag ? $items[0]['content'] : null;
    }

    /**
     * The dotted form of an OBJECT IDENTIFIER's content.
     */
    public static function oidString(string $content): string
    {
        if ($content === '') {
            return '';
        }
        $first = ord($content[0]);
        $parts = [intdiv($first, 40) > 2 ? 2 : intdiv($first, 40), $first - 40 * min(2, intdiv($first, 40))];
        $value = 0;
        for ($i = 1, $length = strlen($content); $i < $length; $i++) {
            $value = ($value << 7) | (ord($content[$i]) & 0x7F);
            if ((ord($content[$i]) & 0x80) === 0) {
                $parts[] = $value;
                $value = 0;
            }
        }

        return implode('.', $parts);
    }

    /**
     * The extensions of an X.509 certificate by OID: whether critical, and the value.
     *
     * @return array<string, list<array{critical: bool, value: string}>>|null Null when malformed
     */
    public static function certificateExtensions(string $certificate): ?array
    {
        $tbs = self::items(self::single($certificate, 0x30) ?? '')[0] ?? null;
        $fields = $tbs === null || $tbs['tag'] !== 0x30 ? null : self::items($tbs['content']);
        if ($fields === null) {
            return null;
        }

        $extensions = [];
        foreach ($fields as $field) {
            // [3] EXPLICIT Extensions
            if ($field['tag'] !== 0xA3) {
                continue;
            }
            foreach (self::items(self::single($field['content'], 0x30) ?? '') ?? [] as $extension) {
                $parts = $extension['tag'] === 0x30 ? self::items($extension['content']) : null;
                if ($parts === null || count($parts) < 2 || $parts[0]['tag'] !== 0x06) {
                    return null;
                }
                $critical = count($parts) === 3 && $parts[1]['tag'] === 0x01 && $parts[1]['content'] !== "\0";
                $value = end($parts);
                if ($value['tag'] !== 0x04) {
                    return null;
                }
                $extensions[self::oidString($parts[0]['content'])][] = ['critical' => $critical, 'value' => $value['content']];
            }
        }

        return $extensions;
    }
}
