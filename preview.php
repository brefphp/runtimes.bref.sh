<?php declare(strict_types=1);

// Local preview only (`make preview`): serves the Lambda handler with PHP's built-in server.

use Nyholm\Psr7\ServerRequest;

$handler = require __DIR__ . '/handler.php';

$request = (new ServerRequest($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI']))->withQueryParams($_GET);
$response = $handler->handle($request);

http_response_code($response->getStatusCode());
foreach ($response->getHeaders() as $name => $values) {
    header($name . ': ' . implode(', ', $values));
}
echo $response->getBody();
