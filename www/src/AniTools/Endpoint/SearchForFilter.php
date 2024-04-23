<?php

declare(strict_types=1);

namespace AniTools\Endpoint;

use AniTools\APIService;
use Aura\Router\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class SearchForFilter implements EndpointInterface
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
        $route->name('search-for-filter');
        $route->path('/searchForFilter/{filter}');

        return $route;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $filter = $request->getAttribute('filter', null);

        if ($filter === null) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => 'Missing filter']),
            );
        }

        $queryParams = $request->getQueryParams();

        return new Response(
            200,
            $response->getHeaders(),
            json_encode($this->apiService->searchForFilter($filter, $queryParams['q'])),
        );
    }
}
