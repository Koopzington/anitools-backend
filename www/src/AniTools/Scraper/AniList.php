<?php

declare(strict_types=1);

namespace AniTools\Scraper;

use AniTools\Util\AniListClient;
use GuzzleHttp\Exception\RequestException;
use Symfony\Component\Console\Cursor;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\Output;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

final class AniList implements ScraperInterface
{
    public const SCRAPER_NAME = 'anilist';

    public const VALID_DATATYPES = [
        'all-media-data',
        'anime',
        'manga',
        'characters',
        'staff',
        'community-lists',
        'gamblers',
        'challenge',
        'activities',
        'media-tag-collection',
    ];

    private const MEDIA_QUERY = '
    fragment data on Page {
        media (type: $mediaType) {
            id
            idMal
            title {
                native
                romaji
                english
            }
            description
            season
            seasonYear
            format
            countryOfOrigin
            tags {
                name
                rank
                isMediaSpoiler
                isGeneralSpoiler
            }
            genres
            episodes
            duration
            source (version: 3)
            averageScore
            meanScore
            popularity
            favourites
            status(version: 2)
            isAdult
            studios {
                edges {
                    node {
                        name
                    }
                    isMain
                }
            }
            stats {
                statusDistribution {
                    status
                    amount
                }
            }
            chapters
            volumes
            reviews {
                pageInfo {
                    total
                }
            }
            startDate {
                year
                month
                day
            }
            endDate {
                year
                month
                day
            }
            coverImage {
                large
            }
            externalLinks {
                site
            }
            relations {
                pageInfo {
                    hasNextPage
                }
                edges {
                    node {
                        id
                    }
                    relationType (version: 2)
                }
            }
            synonyms
        }
    }';

    private const MEDIA_RELATION_QUERY = '
    query ($mediaType: MediaType $page: Int $page2: Int $ids: [Int]) {
        Page (page: $page) {
            pageInfo {
                hasNextPage
            }
            media (type: $mediaType id_in: $ids) {
                id
                relations (page: $page2) {
                    pageInfo {
                        hasNextPage
                    }
                    edges {
                        node {
                            id
                        }
                        relationType (version: 2)
                    }
                }
            }
        }
    }';

    private const CHARACTER_QUERY = '
    fragment data on Page {
        characters {
            id
            name {
                first
                middle
                last
                native
                alternative
            }
            description
            image {
                medium
            }
            gender
            dateOfBirth {
                year
                month
                day
            }
            bloodType
            favourites
            media {
                pageInfo {
                    hasNextPage
                }
                edges {
                    node {
                        id
                    }
                    characterRole
                    voiceActors {
                        id
                        languageV2
                    }
                }
            }
        }
    }';

    private const MEDIA_CHARACTER_QUERY = '
    query ($page: Int $page2: Int $ids: [Int]) {
        Page(page: $page) {
            pageInfo {
                hasNextPage
            }
            characters (id_in: $ids) {
                id
                media (page: $page2) {
                    pageInfo {
                        hasNextPage
                    }
                    edges {
                        node {
                            id
                        }
                        characterRole
                        voiceActors {
                            id
                            languageV2
                        }
                    }
                }
            }
        }
    }';

    private const STAFF_QUERY = '
    fragment data on Page {
        staff {
            id
            name {
                first
                middle
                last
                native
                alternative
            }
            image {
                medium
            }
            description
            gender
            dateOfBirth {
                year
                month
                day
            }
            dateOfDeath {
                year
                month
                day
            }
            yearsActive
            homeTown
            bloodType
            favourites
            staffMedia {
                pageInfo {
                    hasNextPage
                }
                edges {
                    node {
                        id
                    }
                    staffRole
                }
            }
        }
    }';

    private const MEDIA_STAFF_QUERY = '
    query ($page: Int $page2: Int $ids: [Int]) {
        Page (page: $page) {
            pageInfo {
                hasNextPage
            }
            staff (id_in: $ids) {
                id
                staffMedia (page: $page2) {
                    pageInfo {
                        hasNextPage
                    }
                    edges {
                        node {
                            id
                        }
                        staffRole
                    }
                }
            }
        }
    }';

    private const COMMUNITY_LIST_QUERY = '
    query {
        MediaListCollection(userName: "AWC", type: ANIME) {
            lists {
                isCustomList
                name
                entries {
                    media {
                        id
                    }
                }
            }
        }
    }';

    private const CHALLENGE_THREAD_QUERY = '
    query ($thread: Int) {
        Thread (id: $thread) {
            body
        }
    }';

    private const GAMBLERS_THREAD_QUERY = '
    query ($page: Int $thread: Int) {
        Page (page: $page) {
            pageInfo {
                hasNextPage
            }
            threadComments (threadId: $thread) {
                id
                user {
                    name
                }
                comment
                childComments
            }
        }
    }';

    private const USER_QUERY = '
    query ($userName: String) {
        User (name: $userName) {
            id
        }
    }';

    private const ACTIVITIES_QUERY = '
    fragment data on Page {
        activities(userId: $userId type: MEDIA_LIST) {
            ... on ListActivity {
                id
                createdAt
                media {
                    id
                }
                progress
                status
            }
        }
    }';

    private const MEDIA_TAG_COLLECTION_QUERY = '
    query {
        MediaTagCollection {
            category
            name
            description
        }
    }';

    private const QUERY_MAP = [
        'anime' => self::MEDIA_QUERY,
        'manga' => self::MEDIA_QUERY,
        'characters' => self::CHARACTER_QUERY,
        'staff' => self::STAFF_QUERY,
        'challenge' => self::CHALLENGE_THREAD_QUERY,
        'activities' => self::ACTIVITIES_QUERY,
    ];

    private const RELATION_QUERY_MAP = [
        'characters' => self::MEDIA_CHARACTER_QUERY,
        'staff' => self::MEDIA_STAFF_QUERY,
        'relations' => self::MEDIA_RELATION_QUERY,
    ];

    // Contains the maximum amounts of pages that can get requestes at once with the queries hitting maximum complexity
    private const BATCH_MAP = [
        'anime' => 8,
        'manga' => 8,
        'characters' => 17,
        'staff' => 15,
        'activities' => 50,
    ];

    // If not in there, Int will be assumed
    private const QUERY_VARS_TYPE_MAP = [
        'mediaType' => 'MediaType',
    ];

    private string $file;
    private string $mediaType;
    /** @var array<int, mixed> */
    private array $data;
    // Contains ids of types with more relational data, used for the request
    /** @var int[] */
    private array $idsWithMoreData;
    // Contains ids of types that have even more data after a fetchRelationalData walkthrough
    /** @var int[] */
    private array $idsWithYetMoreData = [];
    /** @var array<int, mixed> */
    private array $relationalData;
    private OutputInterface $output;
    private ProgressBar $progressBar;
    private Cursor $cursor;
    /** @var array<string, mixed> */
    private array $progress = [];

    public function __construct(OutputInterface $output)
    {
        $this->output = $output;
        $this->output->setVerbosity(Output::VERBOSITY_VERY_VERBOSE);
        $this->cursor = new Cursor($output);

        // Prepend message placeholder in the formats
        $format = ProgressBar::getFormatDefinition(ProgressBar::FORMAT_VERY_VERBOSE);
        $format = "%message%\n" . $format;
        ProgressBar::setFormatDefinition(ProgressBar::FORMAT_VERY_VERBOSE, $format);
        $format = ProgressBar::getFormatDefinition('very_verbose_nomax');
        $format = "%message%\n" . $format;
        ProgressBar::setFormatDefinition('very_verbose_nomax', $format);
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
        $progress['idsWithYetMoreData'] = $this->idsWithYetMoreData;
        $progress['relationalData'] = $this->relationalData;

        if (\count($this->data) > 0 && $this->progress !== []) {
            file_put_contents($this->file . '.wip', json_encode($progress));
        }
    }

    public function scrape(string $mediaType, string $userName = null): int
    {
        $mediaType = strtolower($mediaType);

        switch ($mediaType) {
            case 'anime':
            case 'manga':
                $this->output->writeln('Scraping ' . $mediaType . ' data');
                $this->getBatchedPageData($mediaType, ['mediaType' => strtoupper($mediaType)]);
                break;
            case 'characters':
            case 'staff':
                $this->output->writeln('Scraping ' . $mediaType . ' data');
                $this->getBatchedPageData($mediaType, []);
                break;
            case 'community-lists':
                $this->scrapeCommunityLists();
                break;
            case 'gamblers':
                $this->mediaType = $mediaType;
                $filename = 'data/import/awc-gamblers-bot-picks.json';
                $this->output->writeln('Scraping Gambler\'s 1.1 thread');
                $picks = $this->scrapeGamblerBotPicks(6171);
                $this->output->writeln('Scraping Gambler\'s 2.0 thread');
                $picks += $this->scrapeGamblerBotPicks(28272);
                $picks = array_map("unserialize", array_unique(array_map("serialize", $picks)));
                file_put_contents($filename, json_encode($picks, JSON_UNESCAPED_UNICODE));
                $this->output->writeln('Done.');
                break;
            case 'all-media-data':
                $this->output->writeln('Scraping anime data');
                $this->getBatchedPageData('anime', ['mediaType' => 'ANIME']);
                $this->output->writeln('Scraping manga data');
                $this->getBatchedPageData('manga', ['mediaType' => 'MANGA']);
                $this->output->writeln('Scraping character data');
                $this->getBatchedPageData('characters', []);
                $this->output->writeln('Scraping staff data');
                $this->getBatchedPageData('staff', []);
                $this->output->writeln('Scraping community lists data');
                $this->scrapeCommunityLists();
                break;
            case 'activities':
                $response = AniListClient::request(self::USER_QUERY, ['userName' => $userName]);
                if ($response['data'] === []) {
                    $this->output->writeln('User not found.');

                    return Command::FAILURE;
                }

                $userId = $response['data']['User']['id'];

                $vars = ['userId' => $userId];
                $this->getBatchedPageData($this->mediaType, $vars);

                break;
            case 'media-tag-collection':
                $this->output->writeln('Scraping media tag collection');
                $this->scrapeMediaTagCollection();
                break;
            default:
                return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function scrapeMediaTagCollection(): void
    {
        $this->mediaType = 'media-tag-collection';
        $filename = 'data/import/data-media-tag-collection.json';
        if (file_exists($filename)) {
            unlink($filename);
        }
        $response = AniListClient::request(self::MEDIA_TAG_COLLECTION_QUERY);
        file_put_contents($filename, json_encode($response['data']['MediaTagCollection']));
    }

    private function scrapeCommunityLists(): void
    {
        $this->mediaType = 'community-lists';
        $filename = 'data/import/data-community-lists.json';
        $data = [];

        $response = AniListClient::request(self::COMMUNITY_LIST_QUERY);
        foreach ($response['data']['MediaListCollection']['lists'] as $list) {
            if (! $list['isCustomList']) {
                continue;
            }
            $data[$list['name']] = [];
            foreach ($list['entries'] as $entry) {
                $data[$list['name']][] = $entry['media']['id'];
            }
        }
        file_put_contents($filename, json_encode($data), JSON_UNESCAPED_UNICODE);
    }

    /*
    private const VALID_CHALLENGE_TYPES = [
        'bingo',
        'staff_picks',
    ];

    private function scrapeChallengeThread(int $threadId, string $type): void
    {
        if (!in_array($type, self::VALID_CHALLENGE_TYPES)) {
            $this->output->writeln('<error>Invalid Challenge type provided</error>');
        }
        $response = AniListClient::request(self::CHALLENGE_THREAD_QUERY, ['thread' => $threadId]);

        $body = $response['data']['Thread']['body'];
    }
    */

    /** @return array<int, array<string, int>> */
    private function scrapeGamblerBotPicks(int $threadId): array
    {
        $gamblerBotPicks = [];
        $hasNextPage = true;
        $page = 1;
        $pattern = "|https:\/\/anilist.co\/anime\/(\d+)\/|";
        $this->progressBar = new ProgressBar($this->output);
        $this->progressBar->setMessage('Running...');
        $this->progressBar->start();
        while ($hasNextPage === true) {
            try {
                $data = AniListClient::request(
                    self::GAMBLERS_THREAD_QUERY,
                    ['page' => $page, 'thread' => $threadId]
                )['data']['Page'];
                $hasNextPage = $data['pageInfo']['hasNextPage'];
                foreach ($data['threadComments'] as $comment) {
                    if ($comment['childComments'] === null) {
                        continue;
                    }
                    foreach ($comment['childComments'] as $reply) {
                        if (! in_array($reply['user']['name'], ['AWCbotchan', 'TrapperHell'])) {
                            continue;
                        }
                        preg_match_all($pattern, $reply['comment'], $ids);

                        foreach ($ids[1] as $id) {
                            $gamblerBotPicks[] = [
                                'thread_id' => $threadId,
                                'comment_id' => $comment['id'],
                                'media_id' => (int) $id,
                            ];
                        }
                    }
                }

                $this->progressBar->advance();
                ++$page;
            } catch (Throwable $e) {
                // Do nothing, continue the loop. We. are. not. finished.
                $this->handleException($e);
            }
        }

        $this->progressBar->finish();
        $this->output->write(PHP_EOL);

        return $gamblerBotPicks;
    }

    private function handleException(Throwable $e): void
    {
        if (
            $e instanceof RequestException
            && $e->hasResponse()
            && $e->getResponse()->getStatusCode() === 429
        ) {
            $this->progressBar->setMessage('<error>We ran into the ratelimiting. Waiting...</error>');
        } else {
            // Clear the progressbar from the current line
            $this->cursor->clearLine();
            // Move the cursor to the beginning of the current line
            $this->cursor->moveToPosition(1, $this->cursor->getCurrentPosition()[1]);
            // Write output
            $this->output->writeln([
                '<error>Encountered an error:</error>',
                '<error>' . $e->getMessage() . '</error>',
            ]);
        }
    }

    /** @param array<string, mixed> $vars */
    private function getBatchedPageData(string $mediaType, ?array $vars): void
    {
        $this->data = [];
        $this->relationalData = [];
        $page = 1;

        $this->mediaType = $mediaType;
        $filename = 'data/import/data-' . $mediaType . '.json';
        $this->file = $filename;

        // Progress file found, overwrite data and continue where we left off
        if (file_exists($this->file . '.wip')) {
            $this->output->writeln('Progress file found, continuing previously stopped scrape');
            $this->progress = json_decode(file_get_contents($this->file . '.wip'), true);
            $page = $this->progress['page'];
            $this->data = $this->progress['data'];
            $this->idsWithYetMoreData = $this->progress['idsWithYetMoreData'];
            $this->relationalData = $this->progress['relationalData'];
        }

        $this->progressBar = new ProgressBar($this->output);
        $this->progressBar->setProgress($page);
        $this->progressBar->start();
        $hasNextPage = true;

        while ($hasNextPage === true) {
            try {
                $lastEntry = $this->fetchBatchedPageData($mediaType, $page, $vars);
                $hasNextPage = $lastEntry['pageInfo']['hasNextPage'];
                $this->progressBar->advance(self::BATCH_MAP[$mediaType]);
                $page += self::BATCH_MAP[$mediaType];
            } catch (Throwable $e) {
                // Do nothing, continue the loop. We. are. not. finished.
                $this->handleException($e);
            }
        }
        $this->progressBar->finish();
        $this->output->write(PHP_EOL);

        if (file_exists($filename)) {
            unlink($filename);
        }
        file_put_contents($filename, json_encode($this->data), JSON_UNESCAPED_UNICODE);

        $this->fetchRelationalData();

        // Delete Progress file
        if (file_exists($this->file . '.wip')) {
            unlink($this->file . '.wip');
        }
    }

    /**
     * @param array<string, mixed> $vars
     * @return array<string, mixed>
     */
    private function fetchBatchedPageData(string $mediatype, int $page, array $vars): array
    {
        $query = self::QUERY_MAP[$this->mediaType];
        $query .= 'query ';
        if (\count($vars) > 0) {
            $keys = array_keys($vars);
            $query .= '( ';
            foreach ($keys as $k) {
                $query .= '$' . $k . ': ' . (self::QUERY_VARS_TYPE_MAP[$k] ?? 'Int');
            }
            $query .= ')';
        }
        $query .= '{ ' . PHP_EOL;

        $batchSize = self::BATCH_MAP[$mediatype];
        $until = $page + $batchSize - 1;
        for ($i = $page; $i < $until; ++$i) {
            $query .= 'p' . $i . ': Page (page: ' . $i . ') { ...data } ' . PHP_EOL;
        }
        // query pageInfo on the last one
        $query .= 'p' . $until . ': Page (page: ' . $until . ') { ...data pageInfo { hasNextPage }}' . PHP_EOL;

        $query .= '}';

        $this->progressBar->setMessage('Requesting pages ' . $page . ' - ' . $until . '...');

        $response = AniListClient::request($query, $vars);
        $data = $response['data'];

        $key = $mediatype;
        if ($mediatype === 'anime' || $mediatype === 'manga') {
            $key = 'media';
        }

        foreach ($data as $page) {
            foreach ($page[$key] as $row) {
                $this->handleRelationalData($row);
                switch ($this->mediaType) {
                    case 'anime':
                    case 'manga':
                        unset($row['relations']);
                        break;
                    case 'characters':
                        unset($row['media']);
                        break;
                    case 'staff':
                        unset($row['staffMedia']);
                        break;
                }
                $this->data[] = $row;
            }
        }

        $this->progress = [
            'page' => $until,
        ];

        return end($response['data']);
    }

    private function fetchRelationalData(): void
    {
        $filename = 'data/import/data-' . $this->mediaType . '-relations.json';

        $this->progressBar = new ProgressBar($this->output, 1);

        $page = 1;
        // The first page was already requested through the initial scrape of the main data
        $page2 = 2;
        $hasNextPage = true;
        $this->progressBar->setMaxSteps((int) ceil(\count($this->idsWithYetMoreData) / 50));
        $this->idsWithMoreData = $this->idsWithYetMoreData;
        $this->idsWithYetMoreData = [];
        if (\count($this->idsWithMoreData) > 0) {
            $this->output->writeln(
                'Scraping additional relational data for '
                . \count($this->idsWithMoreData) . ' entries. Run 1'
            );
            $this->progressBar->start();
            // In theory both conditions should turn true at the same time but let's be safe here
            while (\count($this->idsWithMoreData) > 0 && $hasNextPage === true) {
                try {
                    $hasNextPage = $this->fetchRelationalPage($page, $page2);
                    $this->progressBar->advance();
                    $this->progressBar->setMessage('Running...');
                    ++$page;
                    // After a walkthrough swap the set of ids and prepare the next walkthrough
                    if ($hasNextPage === false && \count($this->idsWithYetMoreData) > 0) {
                        $this->idsWithMoreData = $this->idsWithYetMoreData;
                        $this->idsWithYetMoreData = [];

                        $this->progressBar->finish();
                        $this->output->write(PHP_EOL);
                        $this->output->writeln(
                            'Scraping additional relational data for '
                            . \count($this->idsWithMoreData) . ' entries. Run ' . $page2
                        );
                        $this->progressBar->setMaxSteps((int) ceil(\count($this->idsWithMoreData) / 50));
                        $this->progressBar->start();
                        ++$page2;
                        $page = 1;
                        $hasNextPage = true;
                    }
                } catch (Throwable $e) {
                    // Do nothing, continue the loop. We. are. not. finished.
                    $this->handleException($e);
                }
            }
            $this->progressBar->finish();
            $this->output->write(PHP_EOL);
        }

        $this->output->writeln('Writing relational data to file.');
        file_put_contents($filename, json_encode($this->relationalData));

        $this->output->writeln('Done.');
    }

    private function fetchRelationalPage(int $page, int $page2): bool
    {
        $response = AniListClient::request(
            self::RELATION_QUERY_MAP[$this->mediaType],
            [
                'page' => $page,
                'page2' => $page2,
                'ids' => $this->idsWithMoreData,
                'mediaType' => $this->mediaType,
            ],
        );
        $data = $this->getData($response['data']);

        foreach ($data as $main) {
            $this->handleRelationalData($main);
        }

        return $response['data']['Page']['pageInfo']['hasNextPage'];
    }

    /** @param array<string, mixed> $data */
    private function handleRelationalData(array $data): void
    {
        $hasMore = match ($this->mediaType) {
            'characters' => $this->handleCharacterRelations($data['id'], $data['media']),
            'staff' => $this->handleStaffRelations($data['id'], $data['staffMedia']),
            'anime', 'manga' => $this->handleMediaRelations($data['id'], $data['relations']),
            default => false,
        };

        if ($hasMore) {
            $this->idsWithYetMoreData[] = $data['id'];
        }
    }

    /** @param array<string, mixed> $data */
    private function handleCharacterRelations(int $id, array $data): bool
    {
        foreach ($data['edges'] as $edge) {
            if (! empty($edge['voiceActors'])) {
                // In case there's voice actors, one row per VA
                foreach ($edge['voiceActors'] as $va) {
                    $this->relationalData[$id][] = [
                        'media_id' => $edge['node']['id'],
                        'role' => $edge['characterRole'],
                        'voice_actor_id' => $va['id'],
                        'voice_actor_lang' => $va['languageV2'],
                    ];
                }
            } else {
                $this->relationalData[$id][] = [
                    'media_id' => $edge['node']['id'],
                    'role' => $edge['characterRole'],
                ];
            }
        }

        return $data['pageInfo']['hasNextPage'];
    }

    /** @param array<string, mixed> $data */
    private function handleStaffRelations(int $id, array $data): bool
    {
        foreach ($data['edges'] as $edge) {
            $this->relationalData[$id][] = [
                'media_id' => $edge['node']['id'],
                'role' => $edge['staffRole'],
            ];
        }

        return $data['pageInfo']['hasNextPage'];
    }

    /** @param array<string, mixed> $data */
    private function handleMediaRelations(int $id, array $data): bool
    {
        foreach ($data['edges'] as $edge) {
            $this->relationalData[$id][] = [
                'related_media_id' => $edge['node']['id'],
                'relation_type' => $edge['relationType'],
            ];
        }

        return $data['pageInfo']['hasNextPage'];
    }

    /**
     * @param array<string, mixed> $json
     * @return array<int, mixed>
     */
    private function getData(array $json): array
    {
        switch ($this->mediaType) {
            case 'anime':
            case 'manga':
                return $json['Page']['media'];
            case 'characters':
                return $json['Page']['characters'];
            case 'staff':
                return $json['Page']['staff'];
            default:
                throw new \InvalidArgumentException($this->mediaType . ' is not supported by this method.');
        }
    }
}
