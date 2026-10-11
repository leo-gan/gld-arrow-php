<?php

declare(strict_types=1);

namespace Gld\Arrow\Flight;

use Gld\Arrow\Runtime\ArrowException;
use Gld\Arrow\Runtime\Kind;

final class Messages
{
    public static function handshake(int $version, string $payload): string
    {
        $b = '';
        self::putInt($b, 1, $version);
        self::putBytes($b, 2, $payload);
        return $b;
    }

    public static function ticket(string $ticket): string
    {
        $b = '';
        self::putBytes($b, 1, $ticket);
        return $b;
    }

    public static function flightData(string $header, string $body, string $meta = ''): string
    {
        $b = '';
        if ($header !== '') {
            self::putBytes($b, 2, $header);
        }
        if ($meta !== '') {
            self::putBytes($b, 3, $meta);
        }
        self::putBytes($b, 1000, $body);
        return $b;
    }

    public static function putResult(string $meta): string
    {
        $b = '';
        self::putBytes($b, 1, $meta);
        return $b;
    }

    /** @return array{version:int,payload:string} */
    public static function readHandshake(string $raw): array
    {
        $fields = self::parse($raw);
        return [
            'version' => self::findInt($fields, 1, 0),
            'payload' => self::findBytes($raw, $fields, 2),
        ];
    }

    public static function readTicket(string $raw): string
    {
        return self::findBytes($raw, self::parse($raw), 1);
    }

    /** @return array{header:string,body:string,meta:string} */
    public static function readFlightData(string $raw): array
    {
        $fields = self::parse($raw);
        return [
            'header' => self::findBytes($raw, $fields, 2),
            'meta' => self::findBytes($raw, $fields, 3),
            'body' => self::findBytes($raw, $fields, 1000),
        ];
    }

    public static function readPutResult(string $raw): string
    {
        return self::findBytes($raw, self::parse($raw), 1);
    }

    /** @return list<array{num:int,wire:int,start:int,size:int,u:int}> */
    public static function parse(string $raw): array
    {
        $out = [];
        $i = 0;
        $end = strlen($raw);
        while ($i < $end) {
            $tag = self::varint($raw, $i, $end);
            $field = $tag >> 3;
            $wire = $tag & 7;
            if ($field <= 0) {
                throw new ArrowException(Kind::FLIGHT, $i);
            }
            if ($wire === 0) {
                $u = self::varint($raw, $i, $end);
                $out[] = ['num' => $field, 'wire' => 0, 'start' => $i, 'size' => 0, 'u' => $u];
            } elseif ($wire === 2) {
                $ln = self::varint($raw, $i, $end);
                if ($ln < 0 || $i + $ln > $end) {
                    throw new ArrowException(Kind::FLIGHT, $i);
                }
                $out[] = ['num' => $field, 'wire' => 2, 'start' => $i, 'size' => $ln, 'u' => $ln];
                $i += $ln;
            } elseif ($wire === 1) {
                if ($i + 8 > $end) {
                    throw new ArrowException(Kind::FLIGHT, $i);
                }
                $i += 8;
            } elseif ($wire === 5) {
                if ($i + 4 > $end) {
                    throw new ArrowException(Kind::FLIGHT, $i);
                }
                $i += 4;
            } else {
                throw new ArrowException(Kind::FLIGHT, $i);
            }
        }
        return $out;
    }

    private static function putInt(string &$b, int $field, int $v): void
    {
        self::putKey($b, $field, 0);
        self::putVarint($b, $v < 0 ? $v : $v);
    }

    private static function putBytes(string &$b, int $field, string $raw): void
    {
        self::putKey($b, $field, 2);
        self::putVarint($b, strlen($raw));
        $b .= $raw;
    }

    private static function putKey(string &$b, int $field, int $wire): void
    {
        self::putVarint($b, ($field << 3) | $wire);
    }

    private static function putVarint(string &$b, int $v): void
    {
        $x = $v;
        if ($x < 0) {
            $raw = pack('q', $x);
            $lo = unpack('V', substr($raw, 0, 4))[1];
            $hi = unpack('V', substr($raw, 4, 4))[1];
            $parts = [$lo & 0x7F, ($lo >> 7) & 0x7F, ($lo >> 14) & 0x7F, ($lo >> 21) & 0x7F, (($lo >> 28) | (($hi & 0x7) << 4)) & 0x7F];
            $rest = $hi >> 3;
            for ($k = 0; $k < 5; $k++) {
                $b .= chr($parts[$k] | 0x80);
            }
            while ($rest > 0x7F) {
                $b .= chr(($rest & 0x7F) | 0x80);
                $rest >>= 7;
            }
            $b .= chr($rest & 0x7F);
            return;
        }
        while (true) {
            $byte = $x & 0x7F;
            $x >>= 7;
            if ($x !== 0) {
                $b .= chr($byte | 0x80);
            } else {
                $b .= chr($byte);
                break;
            }
        }
    }

    private static function varint(string $raw, int &$i, int $end): int
    {
        $shift = 0;
        $out = 0;
        $guard = 0;
        while ($i < $end && $guard < 10) {
            $byte = ord($raw[$i]);
            $i++;
            $out |= ($byte & 0x7F) << $shift;
            if (($byte & 0x80) === 0) {
                return $out;
            }
            $shift += 7;
            $guard++;
        }
        throw new ArrowException(Kind::FLIGHT, $i);
    }

    /** @param list<array{num:int,wire:int,start:int,size:int,u:int}> $fields */
    private static function findInt(array $fields, int $num, int $default): int
    {
        foreach ($fields as $f) {
            if ($f['num'] === $num && $f['wire'] === 0) {
                return $f['u'];
            }
        }
        return $default;
    }

    /** @param list<array{num:int,wire:int,start:int,size:int,u:int}> $fields */
    private static function findBytes(string $raw, array $fields, int $num): string
    {
        foreach ($fields as $f) {
            if ($f['num'] === $num && $f['wire'] === 2) {
                return substr($raw, $f['start'], $f['size']);
            }
        }
        return '';
    }
}
