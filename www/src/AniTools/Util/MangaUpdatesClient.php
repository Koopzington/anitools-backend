<?php

declare(strict_types=1);

namespace AniTools\Util;

use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\RetryMiddleware;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Spatie\GuzzleRateLimiterMiddleware\RateLimiterMiddleware;

/**
 * @phpstan-type MUSeriesSearchRequestVars array{
 *  page: int,
 *  perpage: int,
 *  orderby: string,
 *  year: int,
 *  type: array<int, string>
 * }
 */
final class MangaUpdatesClient
{
    private const API_URL = 'https://api.mangaupdates.com/v1/';

    private static Client $client;

    /**
     * @param MUSeriesSearchRequestVars | null $variables
     * @return array<string, mixed>
     * */
    public static function request(string $url, array $variables = null): array
    {
        if (! isset(self::$client)) {
            // Only retry requests when the server responds with a 503 (Service Temporarily Unavailable)
            $decider = function (int $retries, RequestInterface $request, ResponseInterface $response = null): bool {
                return $response !== null && $response->getStatusCode() === 503;
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
            $stack->push(RateLimiterMiddleware::perMinute(60));
            $stack->push(Middleware::retry($decider, $delay));
            self::$client = new Client([
                'base_uri' => self::API_URL,
                'handler' => $stack,
            ]);
        }

        if ($variables !== null) {
            $response = self::$client->post($url, ['json' => $variables]);
        } else {
            $response = self::$client->get($url);
        }

        return json_decode($response->getBody()->getContents(), true);
    }
}
