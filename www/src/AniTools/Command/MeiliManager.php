<?php

declare(strict_types=1);

namespace AniTools\Command;

use AniTools\APIService;
use AniTools\DBService;
use AniTools\MapperService;
use AniTools\Scraper\MangaUpdates;
use Meilisearch\Client;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class MeiliManager extends Command
{
    protected static $defaultName = 'app:meili';
    protected static $defaultDescription = 'Manages Meili related tasks';

    private const VALID_TASKS = [
        'update-mu-index',
        'prefilter-unmappable-data',
    ];

    private const JAPANESE_REGEX = '/\p{Katakana}|\p{Hiragana}|\p{Han}/u';
    private const CHINESE_REGEX = '/\p{Script=Han}/u';
    private const KOREAN_REGEX = '/\p{Script=Hangul}/u';

    protected function configure(): void
    {
        $this->addArgument('task', InputArgument::REQUIRED, 'Which task do you want to execute?');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $task = $input->getArgument('task');

        if (! in_array($task, self::VALID_TASKS)) {
            $output->writeln('Invalid task selected');

            return Command::FAILURE;
        }

        return match ($task) {
            'update-mu-index' => $this->updateMangaUpdatesIndex($output),
            'prefilter-unmappable-data' => $this->prefilterUnmappableData($output),
            default => Command::FAILURE,
        };
    }

    private function updateMangaUpdatesIndex(OutputInterface $output): int
    {
        $file = MangaUpdates::DATA_FILE;

        if (! file_exists($file)) {
            $output->writeln('<error>No file present to update the index with</error>');
            return Command::FAILURE;
        }

        $meiliKey = getenv('MEILI_MASTERKEY');
        if ($meiliKey === false) {
            $output->writeln('<error>The MEILI_MASTERKEY environment variable is missing</error>');
            return Command::FAILURE;
        }

        $client = new Client('http://meilisearch:7700', $meiliKey);
        $client->deleteIndex('mangaupdates');

        $client->index('mangaupdates')->updateSettings([
            'filterableAttributes' => [
                'type',
                'genres',
            ],
        ]);
        $client->index('mangaupdates')->updateRankingRules([
            'exactness',
            'words',
            'proximity',
            //'native_title:asc',
            //'other_titles:asc',
            //'description:asc',
            'typo',
            //"proximity",
            //"attribute",
            //"sort",
        ]);

        $data = json_decode(file_get_contents($file), true);

        $forImport = [];
        foreach ($data as $id => $row) {
            // Temporary workaround because the file contains non-allowed types in it
            if (! in_array($row['type'], MangaUpdates::MANGA_TYPES)) {
                continue;
            }

            $nativeRegex = self::JAPANESE_REGEX;
            if ($row['type'] === 'Manhwa') {
                $nativeRegex = self::KOREAN_REGEX;
            }
            if ($row['type'] === 'Manhua') {
                $nativeRegex = self::CHINESE_REGEX;
            }

            // Try to find a title in the original language
            $nativeTitle = null;
            foreach ($row['titles'] as $title) {
                if (preg_match($nativeRegex, $title) === 1) {
                    $nativeTitle = $title;
                    break;
                }
            }

            // Unset if a native title was found, otherwise take the first one available
            // which is likely romaji
            if ($nativeTitle !== null) {
                unset($row['titles'][array_search($nativeTitle, $row['titles'], true)]);
            } else {
                $nativeTitle = array_shift($row['titles']);
            }

            // Exclude filthy Doujinshi as the chances are extremely low they're on AL
            if (strpos($nativeTitle, ' dj ') !== false) {
                continue;
            }

            $forImport[] = [
                'native_title' => $nativeTitle,
                'other_titles' => $row['titles'],
                'description' => $row['description'],
                'id' => $id,
                'type' => $row['type'],
                //'authors' => $row['authors'],
                'genres' => $row['genres'],
            ];
        }

        $client->index('mangaupdates')->deleteAllDocuments();

        $chunks = array_chunk($forImport, 30000);

        foreach ($chunks as $chunk) {
            $client->index('mangaupdates')->addDocuments($chunk, 'id');
        }

        return Command::SUCCESS;
    }

    private function prefilterUnmappableData(OutputInterface $output): int
    {
        $logger = new Logger('API');
        $handler = new StreamHandler('php://stdout', Level::Debug);
        $handler->setFormatter(new LineFormatter(
            "%datetime% %context.username%%channel%.%level_name%: %message%\n",
            'Y-m-d H:i:s',
            true,
            true
        ));
        $logger->pushHandler($handler);

        $meiliKey = getenv('MEILI_MASTERKEY');
        if ($meiliKey === false) {
            $output->writeln('<error>The MEILI_MASTERKEY environment variable is missing</error>');
            return Command::FAILURE;
        }

        $client = new Client('http://meilisearch:7700', $meiliKey);

        $mapperService = new MapperService(
            DBService::getDBConnection(),
            $logger,
            $client,
            new APIService(DBService::getDBConnection(), new DBService($logger), $logger),
        );

        $mapperService->prefilterUnmappableData();

        return Command::SUCCESS;
    }
}
