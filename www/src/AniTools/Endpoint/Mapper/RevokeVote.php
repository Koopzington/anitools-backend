<?php

declare(strict_types=1);

namespace AniTools\Endpoint\Mapper;

use AniTools\Endpoint\EndpointInterface;
use AniTools\MapperService;
use AniTools\Util\AniListOAuthChecker;
use Aura\Router\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use Ramsey\Uuid\Exception\InvalidUuidStringException;
use Ramsey\Uuid\Uuid;
use React\Http\Message\Response;

final class RevokeVote implements EndpointInterface
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
        $route->name('mapper-revoke-vote');
        $route->path('/mapper/revokeVote');
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

        if (! isset($params['vote'])) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => 'Missing paramter vote'])
            );
        }

        try {
            $voteId = Uuid::fromString($params['vote']);
        } catch (InvalidUuidStringException $e) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => 'Vote ID invalid'])
            );
        }

        try {
            $this->mapperService->revokeVote($user, $voteId);

            return new Response(
                200,
                $response->getHeaders(),
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
