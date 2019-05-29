<?php

namespace Amp\Http\Server\Driver\Internal;

use Amp\CancellationToken;
use Amp\Http\Server\Driver\Client;
use Amp\Promise;
use Amp\Socket\EncryptableSocket;
use Amp\Socket\ResourceSocket;
use Amp\Socket\SocketAddress;
use Amp\Success;

/** @internal */
final class DetachedSocket implements EncryptableSocket
{
    /** @var Client */
    private $client;

    /** @var ResourceSocket */
    private $socket;

    /** @var string|null */
    private $buffer;

    /**
     * @param Client         $client
     * @param ResourceSocket $socket
     * @param string Remaining buffer previously read from the socket.
     */
    public function __construct(Client $client, ResourceSocket $socket, string $buffer)
    {
        $this->client = $client;
        $this->socket = $socket;
        $this->buffer = $buffer !== '' ? $buffer : null;
    }

    public function read(): Promise
    {
        if ($this->buffer !== null) {
            $buffer = $this->buffer;
            $this->buffer = null;
            return new Success($buffer);
        }

        return $this->socket->read();
    }

    public function close(): void
    {
        $this->socket->close();

        $this->client->close();
        $this->client = null;
    }

    public function __destruct()
    {
        $this->socket->close();

        if ($this->client) {
            $this->client->close();
        }
    }

    public function write(string $data): Promise
    {
        return $this->socket->write($data);
    }

    public function end(string $finalData = ""): Promise
    {
        return $this->socket->end($finalData);
    }

    public function reference(): void
    {
        $this->socket->reference();
    }

    public function unreference(): void
    {
        $this->socket->unreference();
    }

    public function isClosed(): bool
    {
        return $this->socket->isClosed();
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->socket->getLocalAddress();
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->socket->getRemoteAddress();
    }

    public function getResource()
    {
        return $this->socket->getResource();
    }

    public function setupTls(?CancellationToken $token = null): Promise
    {
        return $this->socket->setupTls($token);
    }

    public function shutdownTls(): Promise
    {
        return $this->socket->shutdownTls();
    }

    public function getTlsState(): int
    {
        return $this->socket->getTlsState();
    }
}
