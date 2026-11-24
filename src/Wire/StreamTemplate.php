<?php

declare(strict_types=1);

namespace Gld\Arrow\Wire;

/**
 * One IPC stream whose schema and buffer lengths stay fixed.
 * writePayloads copies that framing and the live column bytes on every call.
 * A null return means the payloads are a different shape; the caller uses writeStream.
 */
final class StreamTemplate
{
    /**
     * @param list<array{off:int,len:int,id:int}> $pieces non-empty body buffers, in file order
     */
    public function __construct(
        public readonly string $packet,
        public readonly array $pieces,
    ) {
    }

    public function fast(): bool
    {
        return $this->pieces !== [];
    }

    /** @param list<string> $payloads same order and lengths as $pieces */
    public function writePayloads(array $payloads): ?string
    {
        $n = count($this->pieces);
        if ($n === 0 || count($payloads) !== $n) {
            return null;
        }
        $packet = $this->packet;
        $plen = strlen($packet);
        $payloadBytes = 0;
        for ($i = 0; $i < $n; $i++) {
            $piece = $this->pieces[$i];
            $len = strlen($payloads[$i]);
            if ($len !== $piece['len'] || $piece['off'] + $len > $plen) {
                return null;
            }
            $payloadBytes += $len;
        }
        if ($payloadBytes > 256) {
            return $this->writeWide($payloads, $packet, $plen);
        }
        $out = $packet;
        for ($i = 0; $i < $n; $i++) {
            $off = $this->pieces[$i]['off'];
            $payload = $payloads[$i];
            $len = $this->pieces[$i]['len'];
            for ($k = 0; $k < $len; $k++) {
                $out[$off + $k] = $payload[$k];
            }
        }
        return $out;
    }

    /** @param list<string> $payloads */
    private function writeWide(array $payloads, string $packet, int $plen): string
    {
        $out = '';
        $prev = 0;
        foreach ($this->pieces as $i => $piece) {
            $off = $piece['off'];
            $len = $piece['len'];
            if ($off > $prev) {
                $out .= substr($packet, $prev, $off - $prev);
            }
            $out .= $payloads[$i];
            $prev = $off + $len;
        }
        if ($prev < $plen) {
            $out .= substr($packet, $prev);
        }
        return $out;
    }

    /** @return list<string>|null */
    public function readPayloads(string $raw): ?array
    {
        $n = count($this->pieces);
        $packet = $this->packet;
        if ($n === 0 || strlen($raw) !== strlen($packet)) {
            return null;
        }
        if (substr($raw, 0, 16) !== substr($packet, 0, 16)) {
            return null;
        }
        $out = [];
        $prev = 0;
        foreach ($this->pieces as $piece) {
            $off = $piece['off'];
            $len = $piece['len'];
            if ($off < $prev || $off + $len > strlen($raw)) {
                return null;
            }
            $out[] = substr($raw, $off, $len);
            $prev = $off + $len;
        }
        return $out;
    }
}
