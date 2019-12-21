<?php declare(strict_types=1);

use function GuzzleHttp\Promise\unwrap;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

require_once __DIR__ . '/vendor/autoload.php';

$app = new \Slim\App([
    'settings' => [
        'displayErrorDetails' => false,
    ],
]);
$container = $app->getContainer();
$container['view'] = function () {
    return new \Slim\Views\Twig(__DIR__ . '/templates');
};
$regions = [
    'ca-central-1',
    'eu-central-1',
    'eu-north-1',
    'eu-west-1',
    'eu-west-2',
    'eu-west-3',
    'sa-east-1',
    'us-east-1',
    'us-east-2',
    'us-west-1',
    'us-west-2',
    'ap-south-1',
    'ap-northeast-1',
    'ap-northeast-2',
    'ap-southeast-1',
    'ap-southeast-2',
];

$app->get('/', function (ServerRequestInterface $request, ResponseInterface $response) use ($regions) {
    $selectedRegion = $request->getQueryParams()['region'] ?? 'us-east-1';
    if (!in_array($selectedRegion, $regions)) {
        $response->getBody()->write('Unknown region');
        return $response;
    }

    return $this->view->render($response, 'index.html.twig', [
        'layers' => listLayers($selectedRegion),
        'regions' => $regions,
        'selectedRegion' => $selectedRegion,
    ]);
});

$app->get('/embedded', function (ServerRequestInterface $request, ResponseInterface $response) use ($regions) {
    $selectedRegion = $request->getQueryParams()['region'] ?? 'us-east-1';
    if (!in_array($selectedRegion, $regions)) {
        $response->getBody()->write('Unknown region');
        return $response;
    }

    return $this->view->render($response, 'embedded.html.twig', [
        'layers' => listLayers($selectedRegion),
        'regions' => $regions,
        'selectedRegion' => $selectedRegion,
    ]);
});

$app->run();

function listLayers(string $selectedRegion): array
{
    $lambda = new \Aws\Lambda\LambdaClient([
        'version' => 'latest',
        'region' => $selectedRegion,
    ]);

    $layerNames = [
        'php-74',
        'php-74-fpm',
        'php-73',
        'php-73-fpm',
        'php-72',
        'php-72-fpm',
        'console',
    ];

    // Run the API calls in parallel (thanks to async)
    $promises = array_combine($layerNames, array_map(function (string $layerName) use ($lambda, $selectedRegion) {
        return $lambda->listLayerVersionsAsync([
            'LayerName' => "arn:aws:lambda:$selectedRegion:209497400698:layer:$layerName",
            'MaxItems' => 1,
        ]);
    }, $layerNames));

    // Wait on all of the requests to complete. Throws a ConnectException
    // if any of the requests fail
    $results = unwrap($promises);

    $layers = [];
    foreach ($results as $layerName => $result) {
        $versions = $result['LayerVersions'];
        $latestVersion = end($versions);
        $layers[] = [
            'name' => $layerName,
            'version' => $latestVersion['Version'],
            'arn' => $latestVersion['LayerVersionArn'],
        ];
    }

    return $layers;
}
