<?php

declare(strict_types=1);

namespace AniTools\Scraper;

use GuzzleHttp\Client;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

final class MangaDex implements ScraperInterface
{
    public const SCRAPER_NAME = 'mangadex';
    public const VALID_DATATYPES = [
        'mappings',
    ];

    public const MAPPINGS_FILE = 'data/import/mangadex-mappings.json';

    private OutputInterface $output;
    private ?ProgressBar $progressBar = null;
    // Contains the current progress of the scrape, will be used for canceling and resuming the task
    /** @var array<string, mixed> */
    private array $progress = [];
    /** @var array<string, mixed> */
    private array $data = [];
    private ?string $file = null;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function cancel(): void
    {
        if ($this->progressBar !== null) {
            $this->progressBar->finish();
        }
        $this->output->write(PHP_EOL);
        $this->output->writeln('Received SIGINT, saving progress...');
        $progress = $this->progress;
        $progress['data'] = $this->data;
        if (\count($this->data) > 0) {
            file_put_contents($this->file . '.wip', json_encode($progress));
        }
    }

    public function scrape(string $dataType): int
    {
        if (! in_array($dataType, self::VALID_DATATYPES)) {
            throw new \InvalidArgumentException("Datatype '$dataType' not supported for this scraper");
        }

        try {
            match ($dataType) {
                'mappings' => $this->fetchMappings(),
            };
        } catch (\Throwable $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');
            $this->cancel();
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function fetchMappings(): void
    {
        $this->file = self::MAPPINGS_FILE;
        if (! file_exists('data/other/mangaupdates_id_mappings.json')) {
            $this->output->writeln('MangaUpdates legacy ID mapping files doesn\'t exist, retrieving it...');
            file_put_contents(
                'data/other/mangaupdates_id_mappings.json',
                file_get_contents('https://github.com/henrik9999/mangaupdates-old-id-mapping/raw/main/mapping.json')
            );
        }
        $muMappings = json_decode(file_get_contents('data/other/mangaupdates_id_mappings.json'), true);

        $client = new Client();

        // Results to MangaDex are limited at 10k when using offset, thus instead of traditional paginating we're
        // ordering by the creation date and take the creation date of the last result (+1s) as offset
        $queryParams = [
            'limit' => 100,
            'order' => [
                'createdAt' => 'asc',
            ],
            'createdAtSince' => '1970-01-01T00:00:00',
        ];

        // Progress file found, overwrite data and continue where we left off
        if (file_exists($this->file . '.wip')) {
            $this->output->writeln('Progress file found, continuing previously stopped scrape');
            $this->progress = json_decode(file_get_contents($this->file . '.wip'), true);
            $queryParams = $this->progress['queryParams'];
            $this->data = $this->progress['data'];
        }

        $this->progressBar = new ProgressBar($this->output);
        $this->progressBar->start();

        while (true) {
            $result = $client->get('https://api.mangadex.org/manga', [
                'query' => $queryParams,
            ])->getBody()->getContents();
            $result = json_decode($result, true);
            // This should only get executed once as the total amount lowers with each request
            if ($this->progressBar->getMaxSteps() === 0) {
                $this->progressBar->setMaxSteps($result['total']);
            }
            // We reached the end
            if (\count($result['data']) === 0) {
                break;
            }

            foreach ($result['data'] as $manga) {
                $alId = null;
                $muId = null;
                // Skip manga without any external links
                if (! array_key_exists('links', $manga['attributes']) || $manga['attributes']['links'] === null) {
                    $this->progressBar->advance();
                    continue;
                }

                foreach ($manga['attributes']['links'] as $key => $link) {
                    if ($key === 'al') {
                        $alId = (int) $link;
                    }
                    if ($key === 'mu') {
                        // We found a few instances where the IDs contained spaces
                        $muId = trim($link);
                        // This is an old id, we need to map it to the new one
                        if (ctype_digit($muId) && (int) $muId < 200591) {
                            // ID wasn't found in mapping
                            if (! array_key_exists($muId, $muMappings)) {
                                $this->output->writeln($manga['id'] . ' is referring to an unmappable MU ID ' . $muId);
                                continue;
                            } else {
                                $muId = $muMappings[$muId];
                            }
                        }
                        // Convert the string into the int ID we can actually use on the MU API
                        // Covers both the case where MD stores the string ID and the ID got mapped from the if above
                        if (! ctype_digit($muId)) {
                            $muId = intval($muId, 36);
                        }
                    }
                }

                $m = [];
                if ($alId) {
                    $m['al'] = $alId;
                }
                if ($muId) {
                    $m['mu'] = $muId;
                }
                if ($m !== []) {
                    $this->data[$manga['id']] = $m;
                }

                $this->progressBar->advance();
            }
            // Update the timestamp with the creation date of the last manga in the result +1 second
            $queryParams['createdAtSince'] = date(
                'Y-m-d\TH:i:s',
                strtotime(
                    end($result['data'])['attributes']['createdAt']
                ) + 1
            );
            $this->progress = [
                'queryParams' => $queryParams,
            ];
        }

        $this->progressBar->finish();
        $this->output->write(PHP_EOL);

        file_put_contents($this->file, json_encode($this->data));

        // Progress file is no longer needed
        if (file_exists($this->file . '.wip')) {
            unlink($this->file . '.wip');
        }
        $this->output->writeln('Scrape finished. ' . \count($this->data) . ' mappings were retrieved.');
    }
}
