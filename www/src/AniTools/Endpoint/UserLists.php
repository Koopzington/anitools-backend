<?php

declare(strict_types=1);

namespace AniTools\Endpoint;

use AniTools\APIService;
use Aura\Router\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class UserLists implements EndpointInterface
{
    private APIService $apiService;

    public function __construct(APIService $apiService)
    {
        $this->apiService = $apiService;
    }

    public function getRoute(): Route
    {
        $route = new Route();
        $route->allows('GET');
        $route->handler($this);
        $route->name('user-lists');
        $route->path('/userLists');

        return $route;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $queryParams = $request->getQueryParams();

        if (! isset($queryParams['user_name']) || ! isset($queryParams['media_type'])) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => 'Missing query parameter "media_type" or "user_name"']),
            );
        }

        if (! in_array($queryParams['media_type'], ['ANIME', 'MANGA'])) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => 'Query parameter "media_type" can only be "ANIME" or "MANGA"']),
            );
        }

        $withTimeout = true;
        if (isset($queryParams['force_reload']) && $queryParams['force_reload'] === 'true') {
            $withTimeout = false;
        }

        $result = $this->apiService->getUserLists(
            $queryParams['user_name'],
            $queryParams['media_type'],
            $withTimeout
        );

        if (isset($result['errors'])) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode($result),
            );
        }

        return new Response(
            Response::STATUS_OK,
            $response->getHeaders(),
            json_encode($result),
        );
    }
}
