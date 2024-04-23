<?php

declare(strict_types=1);

namespace AniTools\Scraper;

use AniTools\Util\MangaUpdatesClient;
use GuzzleHttp\Exception\RequestException;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutputInterface;

/**
 * @phpstan-import-type MUSeriesSearchRequestVars from MangaUpdatesClient
 *
 * @phpstan-type MangaUpdatesSeriesSearch array{
 *  total_hits: int,
 *  page: int,
 *  per_page: int,
 *  results: array<int, array{
 *    record: array{
 *      series_id: int,
 *      last_updated: array{ timestamp: int }
 *    }
 *  }>
 * }
 *
 * @phpstan-type MangaUpdatesSeriesInfo array{
 *  title: string,
 *  associated: array<int, array{ title: string }>,
 *  last_updated: array{ timestamp: int },
 *  description: string,
 *  image: array{ url: array{ thumb: string }},
 *  type: string,
 *  year: string,
 *  genres: array<int, array{ genre: string }>,
 *  categories: array<int, array{ category: string }>,
 *  latest_chapter: string,
 *  status: string,
 *  licensed: bool,
 *  completed: bool,
 *  authors: array<int, array{ name: string, type: string }>,
 *  publishers: array<int, array{ publisher_name: string, type: string }>,
 *  publications: array<int, array{ publication_name: string, publisher_name: string }>
 * }
 */
final class MangaUpdates implements ScraperInterface
{
    public const SCRAPER_NAME = 'mangaupdates';

    public const VALID_DATATYPES = [
        'metadata',
        'series',
    ];

    private const META_FILE = 'data/import/mangaupdates-meta.json';
    public const DATA_FILE = 'data/import/mangaupdates.json';

    // These types are allowed on AL's side
    // 'Filipino', 'Indonesian', 'Thai', 'Vietnamese', 'Malaysian' are not
    public const MANGA_TYPES = [
        'Manga',
        'Manhwa',
        'Manhua',
        'Doujinshi',
        'Novel',
    ];

    private ConsoleOutputInterface $output;
    private ProgressBar $progressBar;
    // Contains the current progress of the scrape, will be used for canceling and resuming the task
    /** @var array<string, mixed> */
    private array $progress;
    /** @var array<int, array<string, mixed>> | array<int, int> */
    private array $data;
    private string $file;

    public function __construct(ConsoleOutputInterface $output)
    {
        $this->output = $output;
    }

    public function cancel(): void
    {
        $this->progressBar->finish();
        $this->output->write(PHP_EOL);
        $this->output->writeln('Received SIGINT, saving progress...');
        $progress = $this->progress;
        $progress['data'] = $this->data;
        $progress['debugData'] = $this->debugData;
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
            /** @phpstan-ignore-next-line */
            match ($dataType) {
                'metadata' => $this->fetchMetadata(),
                'series' => $this->fetchSeries(),
            };
        } catch (\Exception $e) {
            $this->cancel();
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * This function will fetch MangaUpdates IDs and the timestamps of their last updates
     * Due to the hardcoded limit of 10,000 results we're doing search loops by years and if a year has still more than
     * the limit (probably starting 2023+) we additionally loop through the media types we're interested in
     */
    public function fetchMetadata(): void
    {
        $this->file = self::META_FILE;
        $this->output->writeln('Scraping metadata from MangaUpdates');

        $maxYear = (int) date('Y') + 3;
        //$year = 1920;
        $year = $maxYear;

        $pageSize = 100;
        $this->data = [];

        $variables = [
            'page' => 1,
            'perpage' => $pageSize,
            'orderby' => 'date_added',
            'year' => $year,
            'type' => self::MANGA_TYPES,
        ];
        // Resume scrape if progress file is present
        $progress = null;
        if (file_exists($this->file . '.wip')) {
            $this->output->writeln('Resuming scrape from previous run');
            $content = file_get_contents($this->file . '.wip');
            if ($content === false) {
                $msg = 'The file "' . $this->file . '.wip' . '" exists but couldn\'t be read.';
                $this->output->writeln($msg);
                throw new RuntimeException($msg);
            }
            $progress = json_decode($content, true);
            $this->data = $progress['data'];
            $this->debugData = $progress['debugData'];
            $variables['year'] = $progress['year'];
            $year = $progress['year'];
            $variables['page'] = $progress['page'];
            if (array_key_exists('type', $variables)) {
                $variables['type'] = $progress['type'];
            }
        }

        ProgressBar::setFormatDefinition('custom', '%current%/%max% [%bar%] %percent:3s%% %message%');
        $section1 = $this->output->section();
        $section2 = $this->output->section();
        $yearProgressBar = new ProgressBar($section1, $maxYear - $year);
        $yearProgressBar->setFormat('custom');
        $yearProgressBar->setMessage((string) $year);
        $yearProgressBar->start();
        $this->progressBar = new ProgressBar($section2, 10000);

        while ($variables['year'] >= 1920) {
        //while ($variables['year'] <= $maxYear) {
            $totalHits = 10000;
            $yearProgressBar->setMessage((string) $variables['year']);

            if (\count($variables['type']) === 1) {
                $i = array_search($variables['type'][0], self::MANGA_TYPES);
                for ($i; $i < \count(self::MANGA_TYPES); ++$i) {
                    $this->progressBar->setMessage(self::MANGA_TYPES[$i]);
                    // Filter down to a single type instead of all of them
                    $variables['type'] = [self::MANGA_TYPES[$i]];
                    $this->iterate($pageSize, $totalHits, $variables);
                    $variables['page'] = 1;
                }
                // Reset types for next year
                $variables['type'] = self::MANGA_TYPES;
            } else {
                $this->iterate($pageSize, $totalHits, $variables);
            }

            $yearProgressBar->advance();
            --$variables['year'];
            // Reset page counter at the end
            $variables['page'] = 1;
        }

        $yearProgressBar->finish();
        $this->output->write(PHP_EOL);

        file_put_contents($this->file . '.debug', json_encode($this->debugData, JSON_PRETTY_PRINT));
        file_put_contents($this->file, json_encode($this->data));
        // If a progress file exists, delete it as we're done now
        if (file_exists($this->file . '.wip')) {
            unlink($this->file . '.wip');
        }
    }

    private $debugData = [];

    /** @param MUSeriesSearchRequestVars $variables */
    private function iterate(int $pageSize, int $totalHits, array &$variables): void
    {
        $this->progressBar->start($totalHits);

        $page = $variables['page'];

        while ($page * $pageSize < $totalHits) {
            try {
                $page = $variables['page'];
                /** @var MangaUpdatesSeriesSearch */
                $response = MangaUpdatesClient::request('series/search', $variables);
            } catch (RequestException $e) {
                $this->output->writeln('<error>Got an uncaught error!</error>');
                $this->output->writeln('<error>' . $e->getRequest()->getBody() . '</error>');
                $this->output->writeln('<error>' . $e->getResponse()->getBody() . '</error>');
                throw $e;
            }

            // Updates total with actual value
            $totalHits = $response['total_hits'];
            $this->progressBar->setMaxSteps($totalHits);

            // If total is still 10000 it means we reached the hard cap
            if ($totalHits === 10000 && \count($variables['type']) !== 1) {
                // In this case we filter further down through the type
                $variables['type'] = ['Manga'];
                break;
            }

            $this->debugData[] = [
                'requestVars' => $variables,
                'response' => $response,
            ];
            foreach ($response['results'] as $result) {
                $this->data[$result['record']['series_id']] = $result['record']['last_updated']['timestamp'];
            }

            $this->progress = $variables;
            $this->progressBar->advance(\count($response['results']));
            ++$variables['page'];
        }
        $this->progressBar->setProgress(0);
    }

    public function fetchSeries(): int
    {
        $this->file = self::DATA_FILE;

        if (! file_exists(self::META_FILE) || ! is_readable(self::META_FILE)) {
            $this->output->writeln(
                'The meta file containing MU IDs and when the entries were last updated isn\'t present.'
                . ' Run the metadata scrape first.'
            );
            return Command::FAILURE;
        }

        $content = file_get_contents(self::META_FILE);
        if ($content === false) {
            $this->output->writeln('The file "' . self::META_FILE . '" exists but couldn\'t be read.');
            return Command::FAILURE;
        }

        $metadata = json_decode($content, true);

        $this->data = [];
        $progress = null;
        if (file_exists($this->file . '.wip')) {
            // If a progress file exists, load the data from it
            $this->output->writeln('Resuming scrape from previous run');
            $content = file_get_contents($this->file . '.wip');
            if ($content === false) {
                $this->output->writeln('The file "' . $this->file . '.wip' . '" exists but couldn\'t be read.');
                return Command::FAILURE;
            }

            $progress = json_decode($content, true);
            $this->data = $progress['data'];
        } elseif (file_exists($this->file) && is_readable($this->file)) {
            // If the file already exists, load the data from it, overwrite and save again
            $content = file_get_contents($this->file);
            if ($content === false) {
                $this->output->writeln('The file "' . $this->file . '" exists but couldn\'t be read.');
                return Command::FAILURE;
            }
            $this->data = json_decode($content, true);
        }

        // Contains all IDs that have a later timestamp than what our database reports,
        // meaning the series got updated on MU
        $toFetch = [];
        // To reduce the total amount of queries we only request the data for entries
        // that haven't completed scanlation yet
        $unfinished = [];
        foreach ($this->data as $id => $row) {
            if ($row['scanlation_completed'] === false) {
                $unfinished[$id] = $row;
            }
        }
        foreach ($metadata as $id => $lastUpdated) {
            // Series that needs to be updated
            if (array_key_exists($id, $unfinished) && $lastUpdated > $unfinished[$id]['last_updated']) {
                $toFetch[] = $id;
            }
            // New series
            if (! array_key_exists($id, $unfinished)) {
                $toFetch[] = $id;
            }
        }

        // For sanity
        $toFetch = array_unique($toFetch);
        $amount = \count($toFetch);

        if ($amount === 0) {
            $this->output->writeln('No series to update');
            return Command::SUCCESS;
        }

        sort($toFetch);

        $this->output->writeln('Scraping data for ' . $amount . ' series');

        $i = 0;
        if ($progress !== null) {
            $i = array_search($progress['id'], $toFetch);
        }

        $this->progressBar = new ProgressBar($this->output, $amount - $i);
        $this->progressBar->start();

        for ($i; $i < $amount; ++$i) {
            /** @var int */
            $id = $toFetch[$i];
            $this->progress['id'] = $id;
            try {
                /** @var MangaUpdatesSeriesInfo */
                $response = MangaUpdatesClient::request('series/' . $id);
            } catch (RequestException $e) {
                // Series got deleted
                if ($e->getResponse()->getStatusCode() === 404) {
                    if (array_key_exists($id, $this->data)) {
                        unset($this->data[$id]);
                    }
                    $this->progressBar->advance();
                    continue;
                } else {
                    $this->output->writeln('<error>Got an uncaught error!</error>');
                    $this->output->writeln('<error>' . $e->getRequest()->getBody() . '</error>');
                    $this->output->writeln('<error>' . $e->getResponse()->getBody() . '</error>');
                    $this->cancel();

                    return Command::FAILURE;
                }
            }

            $this->data[$id] = [
                'last_updated' => $response['last_updated']['timestamp'],
                'titles' => array_merge([$response['title']], array_map(function ($x) {
                    return $x['title'];
                }, $response['associated'])),
                'description' => $response['description'],
                'type' => $response['type'],
                'year' => $response['year'],
                'cover' => $response['image']['url']['thumb'],
                'genres' => array_map(function ($x) {
                    return $x['genre'];
                }, $response['genres']),
                'categories' => array_map(function ($x) {
                    return $x['category'];
                }, $response['categories']),
                'latest_chapter' => $response['latest_chapter'],
                'original_status' => $response['status'],
                'licensed' => $response['licensed'],
                'scanlation_completed' => $response['completed'],
                'authors' => $response['authors'],
                'publishers' => $response['publishers'],
                'publications' => $response['publications'],
            ];

            $this->progressBar->advance();
        }
        $this->progressBar->finish();
        $this->output->write(PHP_EOL);

        file_put_contents($this->file, json_encode($this->data));
        // If a progress file exists, delete it as we're done now
        if (file_exists($this->file . '.wip')) {
            unlink($this->file . '.wip');
        }

        return Command::SUCCESS;
    }
}
