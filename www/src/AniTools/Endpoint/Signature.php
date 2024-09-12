<?php

declare(strict_types=1);

namespace AniTools\Endpoint;

use AniTools\SVGGenerator;
use Aura\Router\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class Signature implements EndpointInterface
{
    private SVGGenerator $svgGenerator;

    public function __construct(SVGGenerator $svgGenerator)
    {
        $this->svgGenerator = $svgGenerator;
    }

    public function getRoute(): Route
    {
        $route = new Route();
        $route->allows('GET');
        $route->handler($this);
        $route->name('signature');
        $route->path('/signature');

        return $route;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        $responseHeaders = $response->getHeaders();
        $queryParams = $request->getQueryParams();

        // Check for both user_name and username (legacy)
        if (! isset($queryParams['user_name']) && ! isset($queryParams['username'])) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $responseHeaders,
                json_encode(['error' => 'Missing query parameter "user_name"']),
            );
        }

        $userName = $queryParams['user_name'] ?? $queryParams['username'];

        $bgColor = '#1e293a';
        // If a bg color was passed, validate it and use that one instead
        if (isset($queryParams['bg_color']) && preg_match('/^[\da-f]{6}$/', $queryParams['bg_color']) === 1) {
            $bgColor = '#' . $queryParams['bg_color'];
        }

        $textColor = '#fff';
        if (isset($queryParams['text_color']) && preg_match('/^[\da-f]{6}$/', $queryParams['text_color']) === 1) {
            $textColor = '#' . $queryParams['text_color'];
        }

        $responseHeaders['Content-Type'] = 'image/svg+xml';

        return new Response(
            200,
            $responseHeaders,
            $this->svgGenerator->generate($userName, $bgColor, $textColor),
        );
    }
}
