<?php

require __DIR__ . '/bootstrap.php';

$binOptions = (new Aerys\BinOptions)->loadOptions();
$debug = $binOptions->getDebug();
$config = $binOptions->getConfig();

list($reactor, $server, $hosts) = (new Aerys\Bootstrapper)->boot($debug, $config);

$worker = new Aerys\Watch\ProcessWorker($reactor, $server);

register_shutdown_function([$worker, 'shutdown']);

$worker->start('tcp://127.0.0.1:' . $binOptions->getBackend());
$server->start($hosts);
$reactor->run();