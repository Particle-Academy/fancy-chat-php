<?php

declare(strict_types=1);

/*
 * Run by tests/Unit/PrismDependencyTest.php in its own process: the package's
 * autoloader with prism-graph's namespace pointed at nothing, as for a host that
 * never installed it. Prints what KnowledgeBridge reports, as JSON.
 */

use FancyChat\Prism\KnowledgeBridge;

$loader = require dirname(__DIR__, 2).'/vendor/autoload.php';
$loader->setPsr4('Prism\\Graph\\', [__DIR__.'/no-such-directory']);

$routes = 'mounted';
try {
    KnowledgeBridge::routes(new KnowledgeBridge(scope: static fn (): null => null));
} catch (Throwable $e) {
    $routes = $e::class.': '.$e->getMessage();
}

echo json_encode(['available' => KnowledgeBridge::available(), 'routes' => $routes], JSON_UNESCAPED_SLASHES);
