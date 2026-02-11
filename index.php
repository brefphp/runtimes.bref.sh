<?php declare(strict_types=1);

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use GuzzleHttp\Client;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;

error_reporting(E_ALL ^ E_DEPRECATED);

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
    'eu-south-1',
    'eu-south-2',
    'sa-east-1',
    'us-east-1',
    'us-east-2',
    'us-west-1',
    'us-west-2',
    'ap-east-1',
    'af-south-1',
    'ap-south-1',
    'ap-northeast-1',
    'ap-northeast-2',
    'ap-northeast-3',
    'ap-southeast-1',
    'ap-southeast-2',
    'me-south-1',
];

$app->get('/', function (ServerRequestInterface $request, ResponseInterface $response) use ($regions) {
    $selectedRegion = $request->getQueryParams()['region'] ?? 'us-east-1';
    if (!in_array($selectedRegion, $regions)) {
        $response->getBody()->write('Unknown region');
        return $response;
    }

    $versions = listVersions();
    $selectedVersion = $request->getQueryParams()['version'] ?? $versions[array_key_first($versions)];
    if (!in_array($selectedVersion, $versions)) {
        $response->getBody()->write('Unknown version');
        return $response;
    }

    return $this->view->render($response, 'index.html.twig', [
        'layers' => listLayers($selectedVersion, $selectedRegion),
        'versions' => $versions,
        'regions' => $regions,
        'selectedRegion' => $selectedRegion,
        'selectedVersion' => $selectedVersion,
    ]);
});

$app->get('/embedded', function (ServerRequestInterface $request, ResponseInterface $response) use ($regions) {
    $selectedRegion = $request->getQueryParams()['region'] ?? 'us-east-1';
    if (!in_array($selectedRegion, $regions)) {
        $response->getBody()->write('Unknown region');
        return $response;
    }

    $versions = listVersions();
    $latestVersion = $versions[array_key_first($versions) + 1];

    return $this->view->render($response, 'embedded.html.twig', [
        'layers' => listLayers($latestVersion, $selectedRegion),
        'regions' => $regions,
        'selectedRegion' => $selectedRegion,
    ]);
});

$app->run();

function listLayers(string $version, string $region): array
{
    $cache = new FilesystemAdapter();

    // Caching network calls to improve performance
    $data = $cache->get('layers_' . $version, function (ItemInterface $item) use ($version) {
        $item->expiresAfter(3600);

        $client = new Client();
        $url = 'https://raw.githubusercontent.com/brefphp/bref/' . $version . '/layers.json';

        $response = $client->get($url);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Could not fetch layers from GitHub');
        }
        $json = $response->getBody()->getContents();
        $data = json_decode($json, true);

        return $data;
    });

    $layers = [];
    $accountId = '209497400698';
    if ($version === 'v2' || strpos($version, '2.') === 0) {
        $accountId = '534081306603';
    }
    if ($version === 'v3' || strpos($version, '3.') === 0) {
        $accountId = '873528684822';
    }

    foreach ($data as $name => $regions) {
        if (!isset($regions[$region])) {
            continue;
        }

        $layers[] = [
            'name' => $name,
            'arn' => sprintf('arn:aws:lambda:%s:%s:layer:%s:%s', $region, $accountId, $name, $regions[$region]),
            'version' => $regions[$region],
        ];
    }

    return $layers;
}

function listVersions(): array
{
    $cache = new FilesystemAdapter();

    // Caching network call as GitHub's API is rate limited
    $releases = $cache->get('releases', function (ItemInterface $item) {
        $item->expiresAfter(3600);

        $client = new Client();
        $url = 'https://api.github.com/repos/brefphp/bref/releases';

        $response = $client->get($url);
        if ($response->getStatusCode() !== 200) {
            throw new \RuntimeException('Could not fetch releases from GitHub');
        }
        $json = $response->getBody()->getContents();
        $releases = json_decode($json, true);

        return $releases;
    });

    $versions = [];

    foreach ($releases as $release) {
        // Skip prereleases (e.g., 0.5.14-beta1)
//        if ($release['prerelease']) {
//            continue;
//        }

        // Skip releases prior to 1.0.0
        if (\Composer\Semver\Comparator::lessThan($release['name'], '1.0.0')) {
            continue;
        }

        $versions[] = $release['name'];
    }

    // Sorting is needed as minor/patch releases can be published after major/minor releases
    $versions = \Composer\Semver\Semver::rsort($versions);

    return $versions;
}
