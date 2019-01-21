<?php declare(strict_types=1);

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

    $result = $lambda->listLayers();

    $filter = [
        'php-73',
        'php-73-fpm',
        'php-72',
        'php-72-fpm',
        'console',
    ];

    $layers = [];
    foreach ($result['Layers'] ?? [] as $layer) {
        $layerName = $layer['LayerName'];
        if (! in_array($layerName, $filter)) {
            continue;
        }

        $latestVersion = $layer['LatestMatchingVersion'];
        $latestVersionNumber = $latestVersion['Version'];
        $latestVersionArn = $latestVersion['LayerVersionArn'];
        $layers[] = [
            'name' => $layerName,
            'version' => $latestVersionNumber,
            'arn' => $latestVersionArn,
        ];
    }

    return $layers;
}
