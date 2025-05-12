<?php

declare(strict_types=1);

namespace AniTools\Endpoint;

use AniTools\APIService;
use AniTools\Util\AniListOAuthChecker;
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

        if (! in_array($queryParams['media_type'], ['ANIME', 'MANGA'], true)) {
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

        // Pass the auth token through if present and verified to import media marked as private
        $authToken = null;
        if ($request->hasHeader('Authorization') === true) {
            try {
                AniListOAuthChecker::verify($request->getHeader('Authorization'));
                // Split token and "Bearer"
                $exp = explode(' ', $request->getHeader('Authorization')[0]);
                $authToken = $exp[1];
            } catch (\UnexpectedValueException $e) {
                return new Response(
                    Response::STATUS_BAD_REQUEST,
                    $response->getHeaders(),
                    json_encode(['error' => $e->getMessage()])
                );
            }
        }

        $result = $this->apiService->getUserLists(
            $queryParams['user_name'],
            $queryParams['media_type'],
            $withTimeout,
            $authToken
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
