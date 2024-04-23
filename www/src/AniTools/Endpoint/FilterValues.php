<?php

declare(strict_types=1);

namespace AniTools\Endpoint;

use AniTools\APIService;
use Aura\Router\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class FilterValues implements EndpointInterface
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
        $route->name('filtervalues');
        $route->path('/filterValues');

        return $route;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $queryParams = $request->getQueryParams();

        if (! array_key_exists('media_type', $queryParams)) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => 'Missing query parameter "media_type"']),
            );
        }

        return new Response(
            200,
            $response->getHeaders(),
            json_encode($this->apiService->getFilterValues(strtoupper($queryParams['media_type']))),
        );
    }
}
