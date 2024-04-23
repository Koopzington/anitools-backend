<?php

declare(strict_types=1);

namespace AniTools\Util;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RetryMiddleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Spatie\GuzzleRateLimiterMiddleware\RateLimiterMiddleware;
use Symfony\Component\Console\Helper\ProgressBar;

final class AniListClient
{
    private const API_URL = 'https://graphql.anilist.co';

    private static Client $client;

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public static function request(
        string $query,
        array $vars = [],
        string $accessToken = null,
        ?ProgressBar $progressBar = null
    ): array {
        if (! isset(self::$client)) {
            // Only retry requests when the server responds with a 429 (Too many requests)
            $decider = function (int $retries, RequestInterface $request, ResponseInterface $response = null): bool {
                return $response !== null && $response->getStatusCode() === 429;
            };

            // Function determining the length of the delay
            $delay = function (int $retries, ResponseInterface $response): int {
                // Use the middleware's default method for deciding the length if no header exists
                // The AniList API should always return the header on a 429 but we're just making sure here
                if (! $response->hasHeader('Retry-After')) {
                    return RetryMiddleware::exponentialDelay($retries);
                }

                // If the header exists, use the delay the server instructed us to use
                $retryAfter = $response->getHeaderLine('Retry-After');

                if (! is_numeric($retryAfter)) {
                    $retryAfter = (new DateTime($retryAfter))->getTimestamp() - time();
                }

                return (int) $retryAfter * 1000;
            };

            $stack = HandlerStack::create();
            $stack->push(RateLimiterMiddleware::perMinute(90));
            $stack->push(Middleware::retry($decider, $delay));
            self::$client = new Client([
                'handler' => $stack,
            ]);
        }

        try {
            $options = [
                'json' => [
                    'query' => $query,
                    'variables' => $vars,
                ],
            ];

            if ($accessToken !== null) {
                $options['headers'] = [
                    'Authorization' => 'Bearer ' . $accessToken,
                ];
            }

            $response = self::$client->post(
                self::API_URL,
                $options,
            )->getBody()->getContents();
        } catch (ClientException $e) {
            $response = $e->getResponse()->getBody()->getContents();
        }

        return json_decode($response, true);
    }
}
