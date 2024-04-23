<?php

declare(strict_types=1);

namespace AniTools\Scraper;

use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class AWC implements ScraperInterface
{
    public const SCRAPER_NAME = 'awc';

    public const VALID_DATATYPES = [
        'leaderboard',
    ];

    private const URL = 'https://awc.moe/';

    private Client $client;
    private OutputInterface $output;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
        $this->client = new Client();
    }

    public function scrape(string $dataType): int
    {
        return match ($dataType) {
            'leaderboard' => $this->scrapeLeaderBoard(),
            default => Command::FAILURE
        };
    }

    public function scrapeLeaderBoard(): int
    {
        $this->output->writeln('Scraping AWC Leaderboard.');
        $response = $this->client->get(self::URL . 'leaderboard/')
            ->getBody()->getContents();
        preg_match('/users \= (.*);/m', $response, $result);

        file_put_contents('data/import/awc-leaderboard.json', $result[1]);

        return Command::SUCCESS;
    }
}
