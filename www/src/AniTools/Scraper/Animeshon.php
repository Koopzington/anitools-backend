<?php

declare(strict_types=1);

namespace AniTools\Scraper;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\ClientException;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

final class Animeshon implements ScraperInterface
{
    public const SCRAPER_NAME = 'animeshon';
    public const CROSSREFS_FILE = 'data/import/animeshon-crossrefs.json';

    public const VALID_DATATYPES = [
        'crossrefs',
    ];

    private const MANGA_CROSSREF_QUERY = '
    query ($first: Int $offset: Int ) {
        queryGraphicNovel (first: $first offset: $offset) {
            ...on GraphGraphicNovel {
                crossrefs {
                    ...on CrossReference {
                        namespace
                        externalID
                    }
                }
            }
        }
    }';

    private const LIGHTNOVEL_CROSSREF_QUERY = '
    query ($first: Int $offset: Int ) {
        queryLightNovel (first: $first offset: $offset) {
            ...on GraphLightNovel {
                crossrefs {
                    ...on CrossReference {
                        namespace
                        externalID
                    }
                }
            }
        }
    }';

    private const SERVICEMAP = [
        'myanimelist-net' => 'MyAnimeList',
        'mangadex-org' => 'MangaDex',
    ];

    private const URL = 'https://graphql.animeapis.dev/graphql';
    private Client $client;
    private OutputInterface $output;
    /** @var array<string, string> */
    private array $muIdMap;
    private string $file;
    /** @var array<int, array<string, mixed>> */
    private array $data;
    private ProgressBar $progressBar;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
        $this->client = new Client();
    }

    public function scrape(string $dataType): int
    {
        if (! in_array($dataType, self::VALID_DATATYPES, true)) {
            throw new InvalidArgumentException("Datatype '$dataType' not supported for this scraper");
        }

        $this->file = self::CROSSREFS_FILE;

        if (! file_exists('data/other/mangaupdates_id_mappings.json')) {
            $this->output->writeln('MangaUpdates legacy ID mapping files doesn\'t exist, retrieving it...');
            file_put_contents(
                'data/other/mangaupdates_id_mappings.json',
                file_get_contents('https://github.com/henrik9999/mangaupdates-old-id-mapping/raw/main/mapping.json')
            );
        }
        $this->muIdMap = json_decode(file_get_contents('data/other/mangaupdates_id_mappings.json'), true);

        $this->data = [];
        $this->output->writeln('Scraping crossrefs for manga');
        $this->iterate(self::MANGA_CROSSREF_QUERY);
        $this->output->writeln('Scraping crossrefs for light novels');
        $this->iterate(self::LIGHTNOVEL_CROSSREF_QUERY);

        file_put_contents($this->file, json_encode($this->data));

        return Command::SUCCESS;
    }

    private function iterate(string $query): void
    {
        $key = match ($query) {
            self::MANGA_CROSSREF_QUERY => 'queryGraphicNovel',
            self::LIGHTNOVEL_CROSSREF_QUERY => 'queryLightNovel',
            // Unreachable
            default => null,
        };

        $page = 0;
        $pageSize = 10000;

        $this->progressBar = new ProgressBar($this->output);
        $this->progressBar->start();
        while (true) {
            try {
                $response = $this->client->post(
                    self::URL,
                    [
                        'json' => [
                            'query' => $query,
                            'variables' => [
                                'first' => $pageSize,
                                'offset' => $pageSize * $page,
                            ],
                        ],
                    ],
                )->getBody()->getContents();

                ++$page;
            } catch (ClientException $e) {
                $response = $e->getResponse()->getBody()->getContents();
                $this->output->writeln('Received an error:');
                $this->output->writeln($response);
                continue;
            }

            $responseData = json_decode($response, true);

            // If there's nothing to process it means we've reached the end
            $mangaCount = \count($responseData['data'][$key]);

            if ($mangaCount === 0) {
                break;
            }

            foreach ($responseData['data'][$key] as $manga) {
                $muId = null;
                $refs = [];
                foreach ($manga['crossrefs'] as $crossref) {
                    if ($crossref['namespace'] === 'mangaupdates-com') {
                        // This is an old ID, we need to map it to the new one
                        if (is_numeric($crossref['externalID']) && $crossref['externalID'] < 200591) {
                            // Trusting in the map being complete and an ID not being in it might possibly
                            // mean that the series is no longer on MangaUpdates, so we skip it
                            if (! array_key_exists($crossref['externalID'], $this->muIdMap)) {
                                continue;
                            }

                            $crossref['externalID'] = $this->muIdMap[$crossref['externalID']];
                        }
                        // Now we convert the string to an int we can use on the API
                        // unless we already have a number >= 200591
                        if (is_string($crossref['externalID'])) {
                            $crossref['externalID'] = intval($crossref['externalID'], 36);
                        }
                        $muId = $crossref['externalID'];
                    }

                    // Skip the crossrefs of services we don't care about
                    if (! array_key_exists($crossref['namespace'], self::SERVICEMAP)) {
                        continue;
                    }

                    $refs[] = [
                        'service' => self::SERVICEMAP[$crossref['namespace']],
                        'external_id' => $crossref['externalID'],
                    ];
                }

                // We skip the ones that have no MangaUpdaates ID as this is the sole reason we're doing all of this
                if ($muId === null || \count($refs) === 0) {
                    continue;
                }

                $this->data[$muId] = $refs;
            }
            $this->progressBar->advance($mangaCount);
        }

        $this->progressBar->finish();
        $this->output->write(PHP_EOL);
    }
}
