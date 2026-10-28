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
        'community-lists',
    ];

    private const URL = 'https://awc.moe/';

    private Client $client;
    private OutputInterface $output;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
        $this->client = new Client([
            'headers' => [
                'User-Agent' => 'AniTools/1.0',
            ],
        ]);
    }

    public function scrape(string $dataType): int
    {
        return match ($dataType) {
            'community-lists' => $this->scrapeCommunityLists(),
            default => Command::FAILURE
        };
    }

    public function scrapeCommunityLists(): int
    {
        $this->output->writeln('Scraping AWC Community Lists.');
        $response = $this->client->get(self::URL . 'lists/json')
            ->getBody()->getContents();
        $response = json_decode($response, true);
        
        $data = [];
        foreach ($response as $media) {
            foreach ($media['lists'] as $list) {
                if (! isset($data[$list])) {
                    $data[$list] = [];
                }
                $data[$list][] = $media['mediaId'];
            }
        }

        file_put_contents('data/import/awc-community-lists.json', json_encode($data, JSON_UNESCAPED_UNICODE));

        return Command::SUCCESS;
    }
}
