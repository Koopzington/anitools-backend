<?php

declare(strict_types=1);

use AniTools\DBService;
use AniTools\Endpoint\EndpointInterface;
use AniTools\Endpoint\FilterValues;
use AniTools\Endpoint\Mapper\CreateMapping;
use AniTools\Endpoint\Mapper\GetMangaUpdatesInfo;
use AniTools\Endpoint\Mapper\GetSuggestion;
use AniTools\Endpoint\Mapper\GetUserVotes;
use AniTools\Endpoint\Mapper\NoneFound;
use AniTools\Endpoint\Mapper\RevokeVote;
use AniTools\Endpoint\Search;
use AniTools\Endpoint\SearchForFilter;
use AniTools\Endpoint\SearchStaff;
use AniTools\Endpoint\Signature;
use AniTools\Endpoint\UserLists;
use AniTools\MapperService;
use AniTools\Util\ServerLog;
use Aura\Router\RouterContainer;
use Meilisearch\Client;
use React\Http\Message\Response;

use function React\Async\async;

include 'vendor/autoload.php';

// Checking whether all required environment variables are set
if (
    ! (getenv('DB_USER') &&
    getenv('DB_DATABASE') &&
    getenv('DB_PASSWORD') &&
    getenv('MEILI_MASTERKEY')
    )
) {
    echo 'It seems that this server was started before an .env file was generated.';
    die;
}

$logger = ServerLog::getInstance();
$logger->debug("Getting DB connection");
$conn = DBService::getDBConnection();
$logger->debug("Instantiating DBService");
$dbService = new AniTools\DBService($logger);
$logger->debug("Instantiating Backend");
$backend = new AniTools\APIService($conn, $dbService, $logger);
$logger->debug("Instantiating SVG Generator");
$svgGenerator = new AniTools\SVGGenerator($conn, $logger);
$logger->debug("Instantiating MapperService");
$meili = new Client('http://meilisearch:7700', getenv('MEILI_MASTERKEY'));
$mapperService = new MapperService($conn, $logger, $meili, $backend);

$router = new RouterContainer();
$map = $router->getMap();

$endpoints = [
    new GetSuggestion($mapperService),
    new GetMangaUpdatesInfo($mapperService),
    new CreateMapping($mapperService),
    new NoneFound($mapperService),
    new GetUserVotes($mapperService),
    new RevokeVote($mapperService),
    new Signature($svgGenerator),
    new FilterValues($backend),
    new SearchForFilter($backend),
    new SearchStaff($backend),
    new UserLists($backend),
    new Search($backend),
];

foreach ($endpoints as $endpoint) {
    $map->addRoute($endpoint->getRoute());
}

$logger->debug("Instantiating ReactPHP server");
$server = new React\Http\HttpServer(
    async(function (Psr\Http\Message\ServerRequestInterface $request) use ($router, $logger) {
        $responseHeaders = [
            'Content-Type' => 'application/json',
            'Server'       => '',
            'Date'         => '',
            'Access-Control-Allow-Origin' => '*',
        ];

        $logger->debug("Received " . $request->getMethod() . " request for " . $request->getUri()->getPath());
        $queryParams = $request->getQueryParams();
        if (\count($queryParams) > 0) {
            $logger->debug('Query params:' . json_encode($queryParams, JSON_PRETTY_PRINT));
        }

        $route = $router->getMatcher()->match($request);
        if (! $route) {
            // get the first of the best-available non-matched routes
            $failedRoute = $router->getMatcher()->getFailedRoute();

            // which matching rule failed?
            switch ($failedRoute->failedRule) {
                case 'Aura\Router\Rule\Allows':
                    // Hacky workaround to make OPTIONS requests work
                    if ($request->getMethod() === 'OPTIONS') {
                        $responseHeaders['Access-Control-Allow-Methods'] = implode(',', $failedRoute->allows);
                        $responseHeaders['Access-Control-Allow-Headers'] = 'Authorization, Content-Type';
                        return new Response(
                            Response::STATUS_OK,
                            $responseHeaders,
                            '',
                        );
                    } else {
                        return new Response(
                            Response::STATUS_METHOD_NOT_ALLOWED,
                            $responseHeaders,
                            '',
                        );
                    }
                case 'Aura\Router\Rule\Accepts':
                    return new Response(
                        Response::STATUS_NOT_ACCEPTABLE,
                        $responseHeaders,
                        '',
                    );
                default:
                    return new Response(
                        Response::STATUS_NOT_FOUND,
                            $responseHeaders,
                            '',
                    );
            }
        }

        /** @var EndpointInterface */
        $endpoint = $route->handler;

        // add route attributes to the request
        foreach ($route->attributes as $key => $val) {
            $request = $request->withAttribute($key, $val);
        }

        try {
            return $endpoint(
                $request,
                new Response(
                    Response::STATUS_OK,
                    $responseHeaders,
                    '',
                ),
            );
        } catch (\InvalidArgumentException $e) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $responseHeaders,
                json_encode(['error' => $e->getMessage()]),
            );
        } catch (\Exception $e) {
            if ($e->getPrevious() !== null) {
                $logger->error($e->getPrevious()->getMessage());
                $logger->error($e->getPrevious()->getTraceAsString());
            } else {
                $logger->error($e->getMessage());
                $logger->error($e->getTraceAsString());
            }
            return new Response(
                Response::STATUS_INTERNAL_SERVER_ERROR,
                $responseHeaders,
                json_encode(['error' => 'An unknown error occured']),
            );
        }
    })
);
$server->on('error', function (Throwable $e) use ($logger) {
    if ($e->getPrevious() !== null) {
        $logger->error($e->getPrevious()->getMessage());
        $logger->error($e->getPrevious()->getTraceAsString());
    } else {
        $logger->error($e->getMessage());
        $logger->error($e->getTraceAsString());
    }
});

$port = 8080;
// Optional port param for easier developing
if (isset($argv[1])) {
    $port = $argv[1];
}

$socket = new React\Socket\SocketServer('0.0.0.0:' . $port);
$logger->debug("Start Listening");
$server->listen($socket);

$logger->debug("Server running at http://127.0.0.1:$port");
