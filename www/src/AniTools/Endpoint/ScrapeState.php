<?php

declare(strict_types=1);

namespace AniTools\Endpoint;

use AniTools\ScrapeStateManager;
use Aura\Router\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class ScrapeState implements EndpointInterface
{
    public function getRoute(): Route
    {
        $route = new Route();
        $route->allows(['GET']);
        $route->handler($this);
        $route->name('scrape-state');
        $route->path('/scrapeState');

        return $route;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $result = ScrapeStateManager::getScrapeState();

        return new Response(
            200,
            $response->getHeaders(),
            json_encode($result),
        );
    }
}