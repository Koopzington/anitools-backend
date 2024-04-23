<?php

declare(strict_types=1);

namespace AniTools\Endpoint;

use AniTools\APIService;
use Aura\Router\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class SearchStaff implements EndpointInterface
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
        $route->name('search-staff');
        $route->path('/staff');

        return $route;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $queryParams = $request->getQueryParams();

        return new Response(
            200,
            $response->getHeaders(),
            json_encode($this->apiService->searchStaff($queryParams['q'])),
        );
    }
}
