<?php

declare(strict_types=1);

namespace Gld\Arrow\Flight;

use Gld\Arrow\Runtime\ArrowException;
use Gld\Arrow\Runtime\Kind;

/**
 * In-memory Flight handshake, DoGet, and DoPut. No socket is opened.
 */
final class Loop
{
    public string $schema = '';
    public string $batch = '';

    public function call(string $method, string $request): string
    {
        return match ($method) {
            'Handshake' => $this->handshake($request),
            'DoGet' => $this->doGet($request),
            'DoPut' => $this->doPut($request),
            default => throw new ArrowException(Kind::FLIGHT, 0, $method),
        };
    }

    private function handshake(string $request): string
    {
        $msg = Messages::readHandshake($request);
        return Messages::handshake($msg['version'], $msg['payload']);
    }

    private function doGet(string $request): string
    {
        $ticket = Messages::readTicket($request);
        if ($ticket !== 't1') {
            throw new ArrowException(Kind::FLIGHT, 0, 'ticket');
        }
        return Messages::flightData($this->schema, $this->batch, 'ok');
    }

    private function doPut(string $request): string
    {
        $data = Messages::readFlightData($request);
        $meta = $data['meta'] !== '' ? $data['meta'] : 'ok';
        $this->batch = $data['body'];
        if ($data['header'] !== '') {
            $this->schema = $data['header'];
        }
        return Messages::putResult($meta);
    }
}
