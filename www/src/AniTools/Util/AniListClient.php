<?php

declare(strict_types=1);

namespace AniTools\Util;

use AniTools\Exception\APITimeoutException;
use DateTime;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use GuzzleHttp\Exception\TransferException;
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
    private static Client $timeoutClient;

    private static function createClient(bool $withTimeout): void
    {
        // Only retry requests when the server responds with a 429 (Too many requests)
        $decider = function (
            int $retries,
            RequestInterface $request,
            ResponseInterface $response = null
        ) use ($withTimeout): bool {
            if ($response === null) {
                throw new \UnexpectedValueException('The AL API returned an unexpected response');
            }

            // Shortcircuit 'cause we don't actually want to wait over 2 minutes for this, even if the user wants to
            // force reload their lists
            if ($response->getStatusCode() === 429 && $retries > 3) {
                throw new APITimeoutException(
                    'The AL API Ratelimiting wants us to wait for longer than we want.',
                    $request,
                    $response,
                );
            }
            // In case we got rate-limited and the time to wait is longer than the threshold we skip retrying
            if (
                $withTimeout === true
                && $response->getStatusCode() === 429
                && $response->hasHeader('Retry-After')
            ) {
                // If the header exists, use the delay the server instructed us to use
                $retryAfter = $response->getHeaderLine('Retry-After');

                if (! is_numeric($retryAfter)) {
                    $retryAfter = (new DateTime($retryAfter))->getTimestamp() - time();
                }

                // Skip retrying if we're supposed to wait longer than 10s
                if ($retryAfter > 10) {
                    throw new APITimeoutException(
                        'The AL API Ratelimiting wants us to wait for longer than we want.',
                        $request,
                        $response,
                    );
                }
            }

            return $response->getStatusCode() === 429;
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

            // Add another second for good measure
            ++$retryAfter;

            return (int) $retryAfter * 1000;
        };

        $stack = HandlerStack::create();
        $stack->push(RateLimiterMiddleware::perMinute(90));
        $stack->push(Middleware::retry($decider, $delay));
        if ($withTimeout === true) {
            self::$timeoutClient = new Client([
                'handler' => $stack,
            ]);
        } else {
            self::$client = new Client([
                'handler' => $stack,
            ]);
        }
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    public static function request(
        string $query,
        array $vars = [],
        string $accessToken = null,
        ?ProgressBar $progressBar = null,
        bool $withTimeout = false,
    ): array {
        if ($withTimeout === true  && ! isset(self::$timeoutClient)) {
            self::createClient(true);
        } elseif (! isset(self::$client)) {
            self::createClient(false);
        }

        $client = $withTimeout === true ? self::$timeoutClient : self::$client;

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

            // Let the request to AL timeout after 10 seconds
            if ($withTimeout === true) {
                $options['timeout'] = 10;
            }

            $response = $client->request(
                'POST',
                self::API_URL,
                $options,
            )->getBody()->getContents();
        } catch (ClientException $e) {
            $response = json_decode($e->getResponse()->getBody()->getContents(), true);
            $output = ['errors' => []];
            if ($e instanceof APITimeoutException) {
                return [
                    'warnings' => [
                        [
                            'source' => 'AniTools',
                            'type' => 'timeout',
                            'message' => $e->getMessage(),
                        ],
                    ],
                ];
            } else {
                foreach ($response['errors'] as $e) {
                    $output['errors'][] = [
                        'source' => 'AniList',
                        'message' => $e['message'],
                    ];
                }
            }

            return $output;
        } catch (TransferException $e) {
            return [
                'warnings' => [
                    [
                        'source' => 'AniTools',
                        'message' => 'AniList API didn\'t respond within 5 seconds.',
                    ],
                ],
            ];
        }

        return json_decode($response, true);
    }
}
