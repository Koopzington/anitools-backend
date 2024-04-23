<?php

declare(strict_types=1);

namespace AniTools\Endpoint\Mapper;

use AniTools\Endpoint\EndpointInterface;
use AniTools\MapperService;
use AniTools\Util\AniListOAuthChecker;
use Aura\Router\Route;
use Psr\Http\Message\MessageInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class NoneFound implements EndpointInterface
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
        $route->name('mapper-none-found');
        $route->path('/mapper/noneFound');
        $route->special(function (ServerRequestInterface $request) {
            // Only accept requests if the Authorization header was sent
            return (bool) \count($request->getHeader('Authorization'));
        });

        return $route;
    }

    public function __invoke(ServerRequestInterface $request, MessageInterface $response): ResponseInterface
    {
        $postParams = json_decode((string) $request->getBody(), true);

        if (! array_key_exists('al_id', $postParams)) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => 'Missing either al_id or mu_id']),
            );
        }

        try {
            $user = AniListOAuthChecker::verify($request->getHeader('Authorization'));
        } catch (\UnexpectedValueException $e) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => $e->getMessage()])
            );
        }

        $this->mapperService->createMapping(
            (int) $postParams['al_id'],
            null,
            $user,
        );

        return new Response(
            200,
            $response->getHeaders(),
            '',
        );
    }
}
