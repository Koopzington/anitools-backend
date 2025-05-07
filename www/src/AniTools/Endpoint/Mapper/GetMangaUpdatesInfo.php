<?php

declare(strict_types=1);

namespace AniTools\Endpoint\Mapper;

use AniTools\Endpoint\EndpointInterface;
use AniTools\MapperService;
use AniTools\Util\AniListOAuthChecker;
use Aura\Router\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class GetMangaUpdatesInfo implements EndpointInterface
{
    private MapperService $mapperService;

    public function __construct(MapperService $mapperService)
    {
        $this->mapperService = $mapperService;
    }

    public function getRoute(): Route
    {
        $route = new Route();
        $route->allows('POST');
        $route->handler($this);
        $route->name('mapper-get-manga-updatesinfo');
        $route->path('/mapper/getMangaUpdatesInfo');
        $route->special(function (ServerRequestInterface $request) {
            // Only accept requests if the Authorization header was sent
            return (bool) \count($request->getHeader('Authorization'));
        });

        return $route;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            AniListOAuthChecker::verify($request->getHeader('Authorization'));
        } catch (\UnexpectedValueException $e) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => $e->getMessage()])
            );
        }

        $postParams = json_decode((string) $request->getBody(), true);

        if (! array_key_exists('input', $postParams) || strlen(trim($postParams['input'])) === 0) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => 'Missing value for input']),
            );
        }

        $exp = explode('/', $postParams['input']);
        $id = null;
        // A full URL was likely posted
        if (\count($exp) >= 5) {
            // Convert the string to an integer
            $id = intval($exp[4], 36);
        } elseif (\count($exp) === 1) {
            // Assuming that the user only submitted the ID
            $id = intval($exp[0], 36);
        }

        if ($id === null) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => 'Invalid value for input']),
            );
        }

        try {
            $result = $this->mapperService->getMangaUpdatesInfoFor($id);

            return new Response(
                200,
                $response->getHeaders(),
                json_encode($result, JSON_UNESCAPED_UNICODE),
            );
        } catch (\UnexpectedValueException $e) {
            return new Response(
                500,
                $response->getHeaders(),
                json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE),
            );
        }
    }
}
