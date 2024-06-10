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

final class GetUserVotes implements EndpointInterface
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
        $route->name('mapper-get-user-votes');
        $route->path('/mapper/getUserVotes');
        $route->special(function (ServerRequestInterface $request) {
            // Only accept requests if the Authorization header was sent
            return (bool) \count($request->getHeader('Authorization'));
        });

        return $route;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        try {
            $user = AniListOAuthChecker::verify($request->getHeader('Authorization'));
        } catch (\UnexpectedValueException $e) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => $e->getMessage()])
            );
        }

        $params = json_decode((string) $request->getBody(), true);

        $length  = (int) ($params['length'] ?? 100);
        $start = (int) ($params['start'] ?? 0);
        // Nice try
        if ($length > 100) {
            $length = 100;
        }
        if ($length < 0) {
            $length = 0;
        }
        if ($start < 0) {
            $start = 0;
        }

        $draw = (int) ($params['draw'] ?? 1);

        try {
            $result = $this->mapperService->getUserVotesFor($user, $length, $start);

            $data = [
                'draw' => $draw,
                'recordsTotal' => $result['total'],
                'recordsFiltered' => $result['total'],
                'data' => $result['data'],
            ];

            return new Response(
                200,
                $response->getHeaders(),
                json_encode($data, JSON_UNESCAPED_UNICODE),
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
