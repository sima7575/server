<?php

namespace Amp\Http\Server\Driver;

use Amp\Future;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\ErrorHandler;
use Amp\Http\Server\Options;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Socket\Socket;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Amp\TimeoutCancellation;
use Psr\Log\LoggerInterface as PsrLogger;
use Revolt\EventLoop;
use function Amp\async;

final class SocketClient implements Client
{
    private const SHUTDOWN_TIMEOUT_ON_ERROR = 1;

    private static DefaultErrorHandler $defaultErrorHandler;

    private static int $nextId = 0;

    private int $id;

    private ?TlsInfo $tlsInfo = null;

    private bool $closed = false;

    private bool $isExported = false;

    private HttpDriver $httpDriver;

    /** @var \Closure[]|null */
    private ?array $onClose = [];

    private int $pendingHandlers = 0;

    private int $pendingResponses = 0;

    /**
     * @param Socket $socket
     * @param RequestHandler $requestHandler
     * @param ErrorHandler $errorHandler
     * @param PsrLogger $logger
     * @param Options $options
     * @param TimeoutCache $timeoutCache
     */
    public function __construct(
        private Socket $socket,
        private RequestHandler $requestHandler,
        private ErrorHandler $errorHandler,
        private PsrLogger $logger,
        private Options $options,
        private TimeoutCache $timeoutCache
    ) {
        self::$defaultErrorHandler ??= new DefaultErrorHandler;
        $this->id = self::$nextId++;
    }

    /**
     * Listen for requests on the client and parse them using the HTTP driver generated from the given factory.
     *
     * @throws \Error If the client has already been started.
     */
    public function start(HttpDriverFactory $driverFactory): void
    {
        if (isset($this->httpDriver)) {
            throw new \Error("Client already started");
        }

        EventLoop::queue(function () use ($driverFactory): void {
            try {
                $context = \stream_context_get_options($this->socket->getResource());
                if (isset($context["ssl"])) {
                    $this->setupTls();
                }

                $this->httpDriver = $driverFactory->selectDriver(
                    $this,
                    $this->errorHandler,
                    $this->logger,
                    $this->options
                );

                $requestParser = $this->httpDriver->setup(
                    $this,
                    $this->onMessage(...),
                    $this->write(...),
                );

                $requestParser->current(); // Advance parser to first yield for data.

                while (!$this->isExported && null !== $chunk = $this->socket->read()) {
                    $requestParser->send($chunk);
                }
            } catch (\Throwable $exception) {
                \assert($this->logDebug("Exception while handling client {address}", [
                    'address' => $this->socket->getRemoteAddress(),
                    'exception' => $exception,
                ]));

                $this->close();
            }
        });
    }

    private function logDebug(string $message, array $context = []): bool
    {
        $this->logger->debug($message, $context);

        return true;
    }

    /**
     * Called by start() after the client connects if encryption is enabled.
     */
    private function setupTls(): void
    {
        $this->timeoutCache->update(
            $this->id,
            \time() + $this->options->getTlsSetupTimeout()
        );

        $this->socket->setupTls(new TimeoutCancellation($this->options->getTlsSetupTimeout()));

        $this->tlsInfo = $this->socket->getTlsInfo();

        \assert($this->tlsInfo !== null);
        \assert($this->logDebug("TLS handshake complete with {address} ({tls.version}, {tls.cipher}, {tls.alpn})", [
            $this->socket->getRemoteAddress(),
            $this->tlsInfo->getVersion(),
            $this->tlsInfo->getCipherName(),
            $this->tlsInfo->getApplicationLayerProtocol() ?? "none",
        ]));
    }

    public function getOptions(): Options
    {
        return $this->options;
    }

    public function getPendingResponseCount(): int
    {
        return $this->pendingResponses;
    }

    public function getPendingRequestCount(): int
    {
        if (!isset($this->httpDriver)) {
            return 0;
        }

        return $this->httpDriver->getPendingRequestCount();
    }

    public function isWaitingOnResponse(): bool
    {
        return isset($this->httpDriver) && $this->pendingHandlers > $this->httpDriver->getPendingRequestCount();
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return $this->socket->getRemoteAddress();
    }

    public function getLocalAddress(): SocketAddress
    {
        return $this->socket->getLocalAddress();
    }

    public function isUnix(): bool
    {
        return $this->getRemoteAddress()->getPort() === null;
    }

    public function isEncrypted(): bool
    {
        return $this->tlsInfo !== null;
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return $this->tlsInfo;
    }

    public function isExported(): bool
    {
        return $this->isExported;
    }

    public function getExpirationTime(): int
    {
        return $this->timeoutCache->getExpirationTime($this->id) ?? 0;
    }

    public function updateExpirationTime(int $expiresAt): void
    {
        if ($this->onClose === null) {
            return; // Client closed.
        }

        $this->timeoutCache->update($this->id, $expiresAt);
    }

    public function close(): void
    {
        if ($this->onClose === null) {
            return; // Client already closed.
        }

        $onClose = $this->onClose;
        $this->onClose = null;

        $this->closed = true;

        $this->clear();
        $this->socket->close();

        \assert((function (): bool {
            if (($this->socket->getLocalAddress()->getHost()[0] ?? "") !== "/") { // no unix domain socket
                return $this->logDebug("Close {address} #{clientId}", [
                    'address' => $this->socket->getRemoteAddress(),
                    'clientId' => $this->id,
                ]);
            }

            return $this->logDebug("Close connection on {address} #{clientId}", [
                'address' => $this->socket->getLocalAddress(),
                'clientId' => $this->id,
            ]);
        })());

        foreach ($onClose as $closure) {
            EventLoop::queue(fn () => $closure($this));
        }
    }

    public function onClose(\Closure $onClose): void
    {
        if ($this->onClose === null) {
            EventLoop::queue(fn () => $onClose($this));
        } else {
            $this->onClose[] = $onClose;
        }
    }

    public function stop(float $timeout): void
    {
        if (isset($this->httpDriver)) {
            try {
                async(fn () => $this->httpDriver->stop())->await(new TimeoutCancellation($timeout));
            } finally {
                $this->close();
            }
        } else {
            $this->close();
        }
    }

    private function clear(): void
    {
        unset($this->httpDriver, $this->requestParser);
        $this->timeoutCache->clear($this->id);
    }

    /**
     * Adds the given data to the buffer of data to be written to the client socket. Returns a promise that resolves
     * once the client write buffer has emptied.
     *
     * @param string $data The data to write.
     * @param bool $close If true, close the client after the given chunk of data has been written.
     */
    private function write(string $data, bool $close = false): void
    {
        if ($this->closed) {
            throw new ClientException($this, "Client socket closed");
        }

        $this->socket->write($data);

        if ($close) {
            $this->closed = true;
            $this->socket->end();
        }
    }

    /**
     * Invoked by the HTTP parser when a request is parsed.
     *
     * @param string $buffer Remaining buffer in the parser.
     */
    private function onMessage(Request $request, string $buffer = ''): Future
    {
        \assert($this->logDebug("{http.method} {http.uri} HTTP/{http.version} @ {address}", [
            'http.method' => $request->getMethod(),
            'http.uri' => (string) $request->getUri(),
            'http.version' => $request->getProtocolVersion(),
            'address' => (string) $this->socket->getRemoteAddress(),
        ]));

        return async(fn () => $this->respond($request, $buffer));
    }

    /**
     * Respond to a parsed request.
     */
    private function respond(Request $request, string $buffer): void
    {
        $clientRequest = $request;
        $request = clone $request;

        $this->pendingHandlers++;
        $this->pendingResponses++;

        try {
            $method = $request->getMethod();

            if (!\in_array($method, $this->options->getAllowedMethods(), true)) {
                $response = $this->makeMethodErrorResponse(
                    \in_array($method, HttpDriver::KNOWN_METHODS, true)
                        ? Status::METHOD_NOT_ALLOWED
                        : Status::NOT_IMPLEMENTED
                );
            } elseif ($method === "OPTIONS" && $request->getUri()->getPath() === "") {
                $response = $this->makeOptionsResponse();
            } else {
                $response = $this->requestHandler->handleRequest($request);
            }
        } catch (ClientException) {
            $this->stop(self::SHUTDOWN_TIMEOUT_ON_ERROR);
            $this->close();
            return;
        } catch (\Throwable $exception) {
            $errorType = \get_class($exception);
            $this->logger->error(
                "Unexpected {$errorType} thrown from RequestHandler::handleRequest(), falling back to error handler.",
                $this->createLogContext($exception, $request)
            );

            $response = $this->makeExceptionResponse($request);
        } finally {
            $this->pendingHandlers--;
        }

        if ($this->closed) {
            return; // Client closed before response could be sent.
        }

        if ($response->isUpgraded()) {
            $this->isExported = true;
        }

        $this->httpDriver->write($clientRequest, $response);

        $this->pendingResponses--;

        if ($this->isExported) {
            $this->export($response->getUpgradeHandler(), $request, $response, $buffer);
        }
    }

    private function makeMethodErrorResponse(int $status): Response
    {
        $response = $this->errorHandler->handleError($status);
        $response->setHeader("Allow", \implode(", ", $this->options->getAllowedMethods()));
        return $response;
    }

    private function makeOptionsResponse(): Response
    {
        return new Response(Status::NO_CONTENT, ["Allow" => \implode(", ", $this->options->getAllowedMethods())]);
    }

    /**
     * Used if an exception is thrown from a request handler.
     */
    private function makeExceptionResponse(Request $request): Response
    {
        $status = Status::INTERNAL_SERVER_ERROR;

        try {
            return $this->errorHandler->handleError($status, null, $request);
        } catch (\Throwable $exception) {
            // If the error handler throws, fallback to returning the default error page.
            $errorType = \get_class($exception);
            $this->logger->error(
                "Unexpected {$errorType} thrown from ErrorHandler::handleError(), falling back to default error handler.",
                $this->createLogContext($exception, $request)
            );

            // The default error handler will never throw, otherwise there's a bug
            return self::$defaultErrorHandler->handleError($status, null, $request);
        }
    }

    /**
     * Invokes the export function on Response with the socket upgraded from the HTTP server.
     *
     * @param string $buffer Remaining buffer read from the socket.
     */
    private function export(callable $upgrade, Request $request, Response $response, string $buffer): void
    {
        if ($this->closed) {
            return;
        }

        $this->clear();

        \assert($this->logDebug("Upgrade {address} #{clientId}", [
            'address' => $this->socket->getRemoteAddress(),
            'clientId' => $this->id,
        ]));

        $socket = new UpgradedSocket($this, $this->socket, $buffer);

        try {
            $upgrade($socket, $request, $response);
        } catch (\Throwable $exception) {
            $errorType = \get_class($exception);
            $this->logger->error(
                "Unexpected {$errorType} thrown during socket upgrade, closing connection.",
                $this->createLogContext($exception, $request)
            );

            $this->close();
        }
    }

    private function createLogContext(\Throwable $exception, Request $request): array
    {
        $logContext = ['exception' => $exception];
        if ($this->options->isRequestLogContextEnabled()) {
            $logContext['request'] = $request;
        }

        return $logContext;
    }
}