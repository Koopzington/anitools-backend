<?php

declare(strict_types=1);

namespace AniTools\Endpoint;

use AniTools\APIService;
use AniTools\Util\Filter;
use Aura\Router\Route;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\Http\Message\Response;

final class Search implements EndpointInterface
{
    private const VALID_MEDIA_TYPES = [
        'ANIME',
        'MANGA',
        'CHARACTER',
        'STAFF',
    ];

    private APIService $apiService;

    public function __construct(APIService $apiService)
    {
        $this->apiService = $apiService;
    }

    public function getRoute(): Route
    {
        $route = new Route();
        $route->allows(['GET', 'POST']);
        $route->handler($this);
        $route->name('search');
        $route->path('/');

        return $route;
    }

    public function __invoke(ServerRequestInterface $request, ResponseInterface $response): ResponseInterface
    {
        if ($request->getMethod() === 'GET') {
            $params = $request->getQueryParams();
        } else if ($request->getMethod() === 'POST') {
            $params = json_decode((string) $request->getBody(), true);
        }

        if (! isset($params['mediaType']) || ! in_array($params['mediaType'], self::VALID_MEDIA_TYPES)) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => 'Missing or invalid query parameters "mediaType"']),
            );
        }

        if (! isset($params['columns'])) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => 'Missing query parameters "columns"']),
            );
        }

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

        $order = isset($params['order']) ? $params['order'] : [];
        if (! is_array($order)) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => 'Query parameter "order" should be an array']),
            );
        }

        $draw = (int) ($params['draw'] ?? 1);

        $rawFilterValues = $params['filter'] ?? [];
        if (! is_array($rawFilterValues)) {
            return new Response(
                Response::STATUS_BAD_REQUEST,
                $response->getHeaders(),
                json_encode(['error' => 'Query parameter "filter" should be an array']),
            );
        }
        $filter = new Filter($this->apiService->getFilterValues($params['mediaType']), $rawFilterValues);

        $columns = [];
        foreach ($params['columns'] as $c) {
            // Visibility is an optional status that's only needed for DataTables
            // If people want to use the API outside of the frontend, they don't have to provide this bit
            $c['visible'] = (bool) ($c['visible'] ?? true);
            $columns[$c['name']] = $c;
        }

        $userName = $params['userName'] ?? null;

        $filteredValues = $filter->getValues();

        $results = match ($params['mediaType']) {
            'ANIME' => $this->apiService->searchForMedia(
                $params['mediaType'],
                $filteredValues,
                $columns,
                $start,
                $length,
                $order,
                $userName
            ),
            'MANGA' => $this->apiService->searchForMedia(
                $params['mediaType'],
                $filteredValues,
                $columns,
                $start,
                $length,
                $order,
                $userName
            ),
            'CHARACTER' => $this->apiService->searchForCharacter(
                $filteredValues,
                $columns,
                $start,
                $length,
                $order,
                $userName
            ),
            'STAFF' => $this->apiService->searchForStaff(
                $filteredValues,
                $columns,
                $start,
                $length,
                $order,
                $userName
            ),
        };

        $data = [
            'draw' => $draw,
            'recordsTotal' => $results['total'],
            'recordsFiltered' => $results['filtered'],
            'total_episodes' => $results['total_episodes'] ?? 0,
            'total_volumes' => $results['total_volumes'] ?? 0,
            'total_runtime' => $results['total_runtime'] ?? 0,
            'filtered_episodes' => $results['filtered_episodes'] ?? 0,
            'filtered_volumes' => $results['filtered_volumes'] ?? 0,
            'filtered_runtime' => $results['filtered_runtime'] ?? 0,
            'total_completed' => $results['total_completed'] ?? 0,
            'data' => $results['data'],
        ];

        $responseHeaders = $response->getHeaders();
        $responseHeaders['Server-Timing'] = implode(',', $results['timings']);

        return new Response(
            200,
            $responseHeaders,
            json_encode($data)
        );
    }
}
