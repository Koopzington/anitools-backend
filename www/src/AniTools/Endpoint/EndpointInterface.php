<?php

declare(strict_types=1);

namespace AniTools\Endpoint;

use Aura\Router\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;

interface EndpointInterface
{
    public function getRoute(): Route;
    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface;
}
