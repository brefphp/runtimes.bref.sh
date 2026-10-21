<?php declare(strict_types=1);

namespace App;

use Composer\Semver\Comparator;
use Composer\Semver\Semver;
use GuzzleHttp\Client;
use Nyholm\Psr7\Response;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;
use Symfony\Contracts\Cache\ItemInterface;
use Throwable;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

class RuntimesHandler implements RequestHandlerInterface
{
    private const REGIONS = [
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
        'ap-southeast-3',
        'me-central-1',
        'me-south-1',
    ];

    private Environment $twig;
    private FilesystemAdapter $cache;
    private Client $http;

    public function __construct()
    {
        $this->twig = new Environment(new FilesystemLoader(__DIR__ . '/../templates'));
        $this->cache = new FilesystemAdapter();
        $this->http = new Client();
    }

    public function handle(ServerRequestInterface $request): ResponseInterface
    {
        if (! in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return new Response(405, ['Allow' => 'GET, HEAD'], 'Method not allowed');
        }

        try {
            return match ($request->getUri()->getPath()) {
                '/', '' => $this->index($request),
                '/embedded' => $this->embedded($request),
                default => new Response(404, ['Content-Type' => 'text/plain'], 'Not found'),
            };
        } catch (Throwable $e) {
            error_log((string) $e);
            return new Response(500, ['Content-Type' => 'text/plain'], 'Internal server error');
        }
    }

    private function index(ServerRequestInterface $request): ResponseInterface
    {
        $selectedRegion = $request->getQueryParams()['region'] ?? 'us-east-1';
        if (! in_array($selectedRegion, self::REGIONS, true)) {
            return new Response(200, [], 'Unknown region');
        }

        $versions = $this->listVersions();
        $selectedVersion = $request->getQueryParams()['version'] ?? $versions[array_key_first($versions)];
        if (! in_array($selectedVersion, $versions, true)) {
            return new Response(200, [], 'Unknown version');
        }

        return $this->render('index.html.twig', [
            'layers' => $this->listLayers($selectedVersion, $selectedRegion),
            'versions' => $versions,
            'regions' => self::REGIONS,
            'selectedRegion' => $selectedRegion,
            'selectedVersion' => $selectedVersion,
        ]);
    }

    private function embedded(ServerRequestInterface $request): ResponseInterface
    {
        $selectedRegion = $request->getQueryParams()['region'] ?? 'us-east-1';
        if (! in_array($selectedRegion, self::REGIONS, true)) {
            return new Response(200, [], 'Unknown region');
        }

        $versions = $this->listVersions();
        $latestVersion = $versions[array_key_first($versions) + 1];

        return $this->render('embedded.html.twig', [
            'layers' => $this->listLayers($latestVersion, $selectedRegion),
            'regions' => self::REGIONS,
            'selectedRegion' => $selectedRegion,
        ]);
    }

    private function render(string $template, array $data): ResponseInterface
    {
        return new Response(200, ['Content-Type' => 'text/html; charset=UTF-8'], $this->twig->render($template, $data));
    }

    private function listLayers(string $version, string $region): array
    {
        // Caching network calls to improve performance
        $data = $this->cache->get('layers_' . $version, function (ItemInterface $item) use ($version) {
            $item->expiresAfter(3600);

            $url = 'https://raw.githubusercontent.com/brefphp/bref/' . $version . '/layers.json';
            $response = $this->http->get($url);
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Could not fetch layers from GitHub');
            }

            return json_decode($response->getBody()->getContents(), true);
        });

        $accountId = '209497400698';
        if ($version === 'v2' || str_starts_with($version, '2.')) {
            $accountId = '534081306603';
        }
        if ($version === 'v3' || str_starts_with($version, '3.')) {
            $accountId = '873528684822';
        }

        $layers = [];
        foreach ($data as $name => $regions) {
            if (! isset($regions[$region])) {
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

    private function listVersions(): array
    {
        // Caching network call as GitHub's API is rate limited
        $releases = $this->cache->get('releases', function (ItemInterface $item) {
            $item->expiresAfter(3600);

            $response = $this->http->get('https://api.github.com/repos/brefphp/bref/releases');
            if ($response->getStatusCode() !== 200) {
                throw new \RuntimeException('Could not fetch releases from GitHub');
            }

            return json_decode($response->getBody()->getContents(), true);
        });

        $versions = [];
        foreach ($releases as $release) {
            // Skip releases prior to 1.0.0
            if (Comparator::lessThan($release['name'], '1.0.0')) {
                continue;
            }
            $versions[] = $release['name'];
        }

        // Sorting is needed as minor/patch releases can be published after major/minor releases
        return Semver::rsort($versions);
    }
}
