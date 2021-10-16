#!/usr/bin/env php
<?php

require \dirname(__DIR__) . "/vendor/autoload.php";

use Amp\ByteStream\ResourceOutputStream;
use Amp\CancelledException;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\HttpServer;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler\CallableRequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Status;
use Amp\Log\ConsoleFormatter;
use Amp\Log\StreamHandler;
use Amp\Socket;
use Amp\TimeoutCancellationToken;
use Monolog\Logger;
use function Amp\coroutine;
use function Amp\trapSignal;

// Used for testing against h2spec (https://github.com/summerwind/h2spec)

$cert = new Socket\Certificate(__DIR__ . '/server.pem');

$context = (new Socket\BindContext)
        ->withTlsContext((new Socket\ServerTlsContext)->withDefaultCertificate($cert));

$servers = [
        Socket\Server::listen("0.0.0.0:1338", $context),
        Socket\Server::listen("[::]:1338", $context),
];

$logHandler = new StreamHandler(new ResourceOutputStream(STDOUT));
$logHandler->setFormatter(new ConsoleFormatter);
$logHandler->setLevel(Logger::INFO);
$logger = new Logger('server');
$logger->pushHandler($logHandler);

$server = new HttpServer($servers, new CallableRequestHandler(static function (Request $request) {
    try {
        // Buffer entire body, but timeout after 100ms.
        $body = coroutine(fn () => $request->getBody()->buffer())->await(new TimeoutCancellationToken(0.1));
    } catch (ClientException) {
        // Ignore failure to read body due to RST_STREAM frames.
    } catch (CancelledException) {
        // Ignore failure to read body tue to timeout.
    }

    return new Response(Status::OK, [
            "content-type" => "text/plain; charset=utf-8",
    ], "Hello, World!");
}), $logger);

$server->start();

trapSignal(\SIGINT);

$server->stop();
