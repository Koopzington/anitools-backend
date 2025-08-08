<?php

declare(strict_types=1);

namespace AniTools\Scraper;

use PharData;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

final class MangaBaka implements ScraperInterface
{
    public const SCRAPER_NAME = 'mangabaka';
    public const VALID_DATATYPES = [
        'mappings',
    ];

    public const MAPPINGS_FILE = 'data/import/mangabaka-mappings.json';

    private const DB_URL = 'https://api.mangabaka.dev/v1/database/series.json.tar.gz';
    private const TMP_DIR = 'data/import/mangabaka-db/';

    private OutputInterface $output;

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
    }

    public function scrape(string $dataType): int
    {
        if (! in_array($dataType, self::VALID_DATATYPES, true)) {
            throw new \InvalidArgumentException("Datatype '$dataType' not supported for this scraper");
        }

        try {
            match ($dataType) {
                'mappings' => $this->fetchMappings(),
            };
        } catch (\Throwable $e) {
            $this->output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function fetchMappings(): void
    {
        if (! file_exists(self::TMP_DIR)) {
            mkdir(self::TMP_DIR);
        }

        $this->output->writeln('Downloading database snapshot...');
        // Download the latest snapshot
        file_put_contents(self::TMP_DIR . 'db.tgz', file_get_contents(self::DB_URL));

        $this->output->writeln('Extracting archive...');
        $archive = new PharData(self::TMP_DIR . 'db.tgz');
        $archive->decompress();
        // Delete tgz as we no longer need it
        unlink(self::TMP_DIR . 'db.tgz');
        $archive = new PharData(self::TMP_DIR . 'db.tar');
        $archive->extractTo(self::TMP_DIR);
        // Delete tar as we no longer need it
        unlink(self::TMP_DIR . 'db.tar');

        $data = json_decode(file_get_contents(self::TMP_DIR . 'series.json'), true);
        $mappings = [];

        $keys = [
            'anime_news_network' => 'ann',
            'mangadex' => 'md',
            'manga_updates' => 'mu',
            'my_anime_list' => 'mal',
            'kitsu' => 'ki',
        ];
        foreach ($data as $media) {
            // Skip media that doesn't have AL ids
            if ($media['source']['anilist']['id'] === null) {
                continue;
            }

            $m = [];
            foreach ($keys as $mbKey => $atKey) {
                if ($media['source'][$mbKey]['id'] !== null) {
                    $m[$atKey] = $media['source'][$mbKey]['id'];
                }
            }

            if (isset($m['mu'])) {
                // Convert the string into the int ID we can actually use on the MU API
                // Covers both the case where MD stores the string ID and the ID got mapped from the if above
                if (! ctype_digit($m['mu'])) {
                    $m['mu'] = intval($m['mu'], 36);
                }
            }

            $mappings[$media['source']['anilist']['id']] = $m;
        }

        // Save mappings
        file_put_contents(self::MAPPINGS_FILE, json_encode($mappings));
        unlink(self::TMP_DIR . 'series.json');
        rmdir(self::TMP_DIR);
    }
}
