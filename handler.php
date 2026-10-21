<?php declare(strict_types=1);

error_reporting(E_ALL ^ E_DEPRECATED);

require __DIR__ . '/vendor/autoload.php';

return new App\RuntimesHandler();
