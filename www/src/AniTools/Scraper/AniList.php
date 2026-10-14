<?php

declare(strict_types=1);

namespace AniTools\Scraper;

use AniTools\DBService;
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
        'anime',
        'manga',
        'characters',
        'staff',
        'community-lists',
        'gamblers',
        'challenge',
        'media-tag-collection',
        'media-meta',
        'media-updates',
    ];

    // The amount of entries per page the API let's one request up to
    private const MAX_PER_PAGE = 50;

    private const ANIME_QUERY = '
    Page {
        media (sort: ID id_in: $ids type: ANIME) {
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
            isLicensed
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
                    id
                    node {
                        id
                    }
                    relationType (version: 3)
                }
            }
            synonyms
            updatedAt
        }
    }';

    private const MANGA_QUERY = '
    Page {
        media (sort: ID id_in: $ids type: MANGA) {
            id
            idMal
            title {
                native
                romaji
                english
            }
            description
            format
            countryOfOrigin
            tags {
                name
                rank
                isMediaSpoiler
                isGeneralSpoiler
            }
            genres
            source (version: 3)
            averageScore
            meanScore
            popularity
            favourites
            status(version: 2)
            isAdult
            isLicensed
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
                    id
                    node {
                        id
                    }
                    relationType (version: 3)
                }
            }
            synonyms
            updatedAt
        }
    }';

    private const MEDIA_RELATION_QUERY = '
    Page {
        media (id_in: $ids) {
            id
            relations (page: $page) {
                pageInfo {
                    hasNextPage
                }
                edges {
                    id
                    node {
                        id
                    }
                    relationType (version: 3)
                }
            }
        }
    }';

    private const CHARACTER_QUERY = '
    Page {
        characters (sort: ID id_in: $ids) {
            id
            name {
                first
                middle
                last
                native
                alternative
                alternativeSpoiler
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
            age
            favourites
            media {
                pageInfo {
                    hasNextPage
                }
                edges {
                    id
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
    Page {
        characters (id_in: $ids sort: ID) {
            id
            media (page: $page sort: ID) {
                pageInfo {
                    hasNextPage
                }
                edges {
                    id
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

    private const STAFF_QUERY = '
    Page {
        staff (sort: ID id_in: $ids) {
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
            primaryOccupations
            favourites
            staffMedia {
                pageInfo {
                    hasNextPage
                }
                edges {
                    id
                    node {
                        id
                    }
                    staffRole
                }
            }
        }
    }';

    private const MEDIA_STAFF_QUERY = '
    Page {
        staff (sort: ID id_in: $ids) {
            id
            staffMedia (page: $page sort: ID perPage: 50) {
                pageInfo {
                    hasNextPage
                }
                edges {
                    id
                    node {
                        id
                    }
                    staffRole
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
            threadComments (threadId: $thread sort: ID) {
                id
                user {
                    name
                }
                comment
                childComments
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

    private const MEDIA_META_QUERY = 'Page { media (sort: ID id_in: $ids ) { id type updatedAt }}';

    private const QUERY_MAP = [
        'anime' => self::ANIME_QUERY,
        'manga' => self::MANGA_QUERY,
        'characters' => self::CHARACTER_QUERY,
        'staff' => self::STAFF_QUERY,
        'challenge' => self::CHALLENGE_THREAD_QUERY,
        'meta' => self::MEDIA_META_QUERY,
        'characters-relations' => self::MEDIA_CHARACTER_QUERY,
        'staff-relations' => self::MEDIA_STAFF_QUERY,
        'media-relations' => self::MEDIA_RELATION_QUERY,
    ];

    // Contains the maximum amounts of pages that can get requestes at once with the queries hitting maximum complexity
    private const BATCH_MAP = [
        'anime' => 7,
        'manga' => 8,
        'characters' => 15,
        'staff' => 14,
        'characters-relations' => 30,
        'staff-relations' => 30,
        'media-relations' => 30,
    ];

    private string $file;
    private string $mediaType;
    /** @var array<int, mixed> Contains all the main type data collected during the scrape */
    private array $data;
    /** @var int[] All type queries request the first page of relational data. If an entry has more than that the IDs
     *             will be added to this array which then will be handled further by self::fetchRelationalData()
     */
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
        if (isset($this->progressBar)) {
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

    public function scrape(string $mediaType): int
    {
        $mediaType = strtolower($mediaType);

        switch ($mediaType) {
            case 'media-meta':
                $this->output->writeln('Fetching state of last update of media');
                $this->scrapeMediaMeta();
                break;
            case 'media-updates':
                $this->output->writeln('Fetching updated media data based on meta file');
                $this->scrapeUpdatedMedia('anime');
                $this->scrapeUpdatedMedia('manga');
                break;
            case 'anime':
            case 'manga':
            case 'characters':
            case 'staff':
                $this->output->writeln('Scraping ' . $mediaType . ' data');
                $this->fullScrape($mediaType);
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
                $this->output->writeln('Scraping Gambler\'s 3.0 thread');
                $picks += $this->scrapeGamblerBotPicks(69776);
                $picks = array_map("unserialize", array_unique(array_map("serialize", $picks)));
                file_put_contents($filename, json_encode($picks, JSON_UNESCAPED_UNICODE));
                $this->output->writeln('Done.');
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

    /**
     * @param "anime"|"manga" $mediaType
     */
    private function scrapeUpdatedMedia(string $mediaType): void
    {
        // Load current data
        $this->data = json_decode(file_get_contents('data/import/data-' . $mediaType . '.json'), true);

        // Load current relations
        $this->relationalData = json_decode(
            file_get_contents('data/import/data-' . $mediaType . '-relations.json'),
            true
        );

        $metaData = json_decode(file_get_contents('data/import/data-media-meta.json'), true)[$mediaType];
        $toUpdate = [];

        foreach ($metaData as $id => $updatedAt) {
            // If the media is new or it's reported updatedAt is newer than what we have, add ID to update queue
            if (! isset($this->data[$id]) || $updatedAt > ($this->data[$id]['updatedAt'] ?? 0)) {
                $toUpdate[] = $id;
            }
        }

        // Delete media that was deleted on AL from our JSON file
        $ids = array_keys($this->data);
        $deletedCount = 0;
        foreach ($ids as $id) {
            if (! isset($metaData[$id])) {
                unset($this->data[$id]);
                unset($this->relationalData[$id]);
                ++$deletedCount;
            }
        }

        $this->output->writeln('Found ' . \count($toUpdate) . ' ' . $mediaType . ' that require updating');
        $this->output->writeln($deletedCount . ' ' . $mediaType . ' were deleted');

        // Save the data after deleting removed entries
        file_put_contents(
            'data/import/data-' . $mediaType . '.json',
            json_encode($this->data, JSON_UNESCAPED_UNICODE)
        );
        file_put_contents(
            'data/import/data-' . $mediaType . '-relations.json',
            json_encode($this->relationalData, JSON_UNESCAPED_UNICODE)
        );

        // Update data
        $this->getBatchedData($mediaType, $toUpdate);
    }

    /** @param array<int, int> $ids */
    private function getBatchedData(string $mediaType, array $ids): void
    {
        $this->mediaType = $mediaType;
        $filename = 'data/import/data-' . $mediaType . '.json';
        $this->file = $filename;

        // 2026-05-11 not sure how restoring should work with chunking in place right now
        // Progress file found, overwrite data and continue where we left off
        //if (file_exists($this->file . '.wip')) {
        //    $this->output->writeln('Progress file found, continuing previously stopped scrape');
        //    $this->progress = json_decode(file_get_contents($this->file . '.wip'), true);
        //    $page = $this->progress['page'];
        //    $this->data = $this->progress['data'];
        //    $this->idsWithYetMoreData = $this->progress['idsWithYetMoreData'];
        //    $this->relationalData = $this->progress['relationalData'];
        //}

        $chunks = array_chunk($ids, self::BATCH_MAP[$mediaType] * self::MAX_PER_PAGE);

        $this->progressBar = new ProgressBar($this->output);
        $this->progressBar->setMessage('Fetching data...');
        $this->progressBar->setMaxSteps(\count($chunks));
        $this->progressBar->setProgress(1);
        $this->progressBar->start();

        foreach ($chunks as $chunk) {
            try {
                $this->fetchBatchedData($mediaType, $chunk);
                $this->progressBar->advance();
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
        file_put_contents($filename, json_encode($this->data, JSON_UNESCAPED_UNICODE));

        $this->fetchRelationalData();

        // Delete Progress file
        //if (file_exists($this->file . '.wip')) {
        //    unlink($this->file . '.wip');
        //}
    }

    /**
     * @param array<int, int> $ids
     * @return void
     */
    private function fetchBatchedData(string $mediatype, array $ids): void
    {
        $preparedQuery = $this->getBatchedQuery($mediatype, array_chunk($ids, self::MAX_PER_PAGE, false));

        $response = AniListClient::request($preparedQuery['query'], $preparedQuery['vars']);
        if (isset($response['errors'])) {
            $this->progressBar->finish();
            $this->output->write(PHP_EOL);
            $this->output->writeln(json_encode($response['errors']));
            die;
        }

        $data = $response['data'];

        // The GraphQL API returns anime and manga as "media"
        $key = $mediatype;
        if ($mediatype === 'anime' || $mediatype === 'manga') {
            $key = 'media';
        }

        foreach ($data as $page) {
            foreach ($page[$key] as $row) {
                // Extract info that won't be stored with the mediatype directly
                $this->handleRelationalData($row);
                switch ($mediatype) {
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
                $this->data[$row['id']] = $row;
            }
        }

        /*
        $this->progress = [
            'page' => $until,
        ];
        */
    }

    private function scrapeMediaMeta(): void
    {
        // First we retrieve info on all the media that we already know of
        $data = [
            'anime' => [],
            'manga' => [],
        ];

        // We start by fetching all known media IDs from our own database
        $con = DBService::getDBConnection();
        $sel = $con->createQueryBuilder();
        $sel->select('COUNT(id) as amount');
        $sel->from('media');
        $result = $sel->executeQuery()->fetchAssociative();

        $this->output->writeln($result['amount'] . ' media currently in database');

        // Now we check for newly added media
        // First we get the latest media id from the API
        $newestIdQuery = 'query { Media (sort: ID_DESC) {id}}';
        $response = AniListClient::request($newestIdQuery);
        $newestId = $response['data']['Media']['id'];
        // Then we create a chunked list of theoretical IDs between the newest and 1
        // Theoretical because the media might've been deleted in the meantime
        $idsToCheck = range(1, $newestId);

        $this->output->writeln('Fetching fresh metadata for up to ' . $newestId . ' entries');

        // Build the GQL query we'll use
        $batchSize = 10;
        $queryChunks = array_chunk($idsToCheck, $batchSize * self::MAX_PER_PAGE, false);
        $this->progressBar = new ProgressBar($this->output);
        $this->progressBar->setMaxSteps(\count($queryChunks));
        $this->progressBar->setProgress(1);
        $this->progressBar->start();

        foreach ($queryChunks as $q => $queryChunk) {
            $this->progressBar->setMessage(
                'Requesting pages ' . (($q + 1) * $batchSize) . ' - ' . ((($q + 1) * $batchSize) + $batchSize) . '...'
            );
            $pageChunks = array_chunk($queryChunk, self::MAX_PER_PAGE, false);

            $metaQuery = $this->getBatchedQuery('meta', $pageChunks);

            $response = AniListClient::request($metaQuery['query'], $metaQuery['vars']);
            if (isset($response['errors'])) {
                $this->progressBar->finish();
                $this->output->write(PHP_EOL);
                $this->output->writeln(json_encode($response['errors']));
                die;
            }

            foreach ($response['data'] as $page) {
                foreach ($page['media'] as $row) {
                    $data[strtolower($row['type'])][$row['id']] = $row['updatedAt'];
                }
            }

            $this->progressBar->advance();
        }

        $this->progressBar->finish();
        $this->output->write(PHP_EOL);

        $this->output->writeln('New media count: ' . (\count($data['anime']) + \count($data['manga'])));

        $filename = 'data/import/data-media-meta.json';
        if (file_exists($filename)) {
            unlink($filename);
        }
        file_put_contents($filename, json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function fullScrape(string $mediaType): void
    {
        $this->data = [];
        $this->relationalData = [];

        $APIType = match ($mediaType) {
            'characters' => 'Character',
            'staff' => 'Staff',
            'anime' => 'Media',
            'manga' => 'Media',
            default => null,
        };

        if ($APIType === null) {
            throw new \InvalidArgumentException('Mediatype ' . $mediaType . ' is not allowed for a full scrape yet');
        }

        // First we get the latest id from the API
        $newestIdQuery = 'query { ' . $APIType . ' (sort: ID_DESC) {id}}';
        if ($APIType === 'Media') {
            $newestIdQuery = 'query { ' . $APIType . ' (sort: ID_DESC type: ' . strtoupper($mediaType) . ') {id}}';
        }

        $response = AniListClient::request($newestIdQuery);
        $newestId = $response['data'][$APIType]['id'];
        // Then we create a chunked list of theoretical IDs between the newest and 1
        // Theoretical because the media might've been deleted in the meantime
        $idsToCheck = range(1, $newestId);

        $this->output->writeln('Fetching fresh data for up to ' . $newestId . ' ' . $APIType . ' entries');

        $this->getBatchedData($mediaType, $idsToCheck);
    }

    /**
     * @param array<int, int[]> $chunkedIds
     *
     * @return array{
     *  query: string,
     *  vars: array<string, int[]>
     * }
     */
    private function getBatchedQuery(string $mediaType, array $chunkedIds, ?int $page = null): array
    {
        $query = self::QUERY_MAP[$mediaType];

        $queryVars = [];
        $vars = [];
        if ($page !== null) {
            $vars[] = '$page: Int';
            $queryVars['page'] = $page;
        }
        $pages = [];
        foreach ($chunkedIds as $i => $pageSet) {
            $vars[] = '$ids' . $i . ': [Int]';
            $pages[] = 'p' . $i . ': ' . str_replace('$ids', '$ids' . $i, $query);
            $queryVars['ids' . $i] = $pageSet;
        }

        return [
            'query' => 'query (' . implode(',', $vars) . ') { ' . implode('', $pages) . ' }',
            'vars' => $queryVars,
        ];
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
            if (! (bool) $list['isCustomList']) {
                continue;
            }
            $data[$list['name']] = [];
            foreach ($list['entries'] as $entry) {
                $data[$list['name']][] = $entry['media']['id'];
            }
        }
        file_put_contents($filename, json_encode($data, JSON_UNESCAPED_UNICODE));
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
                        if (! in_array($reply['user']['name'], ['AWCbotchan', 'TrapperHell'], true)) {
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

    private function fetchRelationalData(): void
    {
        $filename = 'data/import/data-' . $this->mediaType . '-relations.json';

        // The first page was already requested through the initial scrape of the main data
        $page = 2;
        $key = $this->mediaType;
        if (in_array($key, ['anime', 'manga'], true)) {
            $key = 'media';
        }
        while (\count($this->idsWithYetMoreData) > 0) {
            $this->output->writeln(
                'Scraping additional relational data for '
                . \count($this->idsWithYetMoreData) . ' entries. Run ' . ($page - 1)
            );
            $idsWithMoreData = array_chunk(
                $this->idsWithYetMoreData,
                self::BATCH_MAP[$this->mediaType . '-relations'] * self::MAX_PER_PAGE,
                false
            );
            $this->idsWithYetMoreData = [];

            $this->progressBar = new ProgressBar($this->output);
            $this->progressBar->setMaxSteps(\count($idsWithMoreData));
            $this->progressBar->setMessage('Fetching data...');
            $this->progressBar->setProgress(1);
            $this->progressBar->start();

            foreach ($idsWithMoreData as $batch) {
                $pageChunks = array_chunk($batch, self::MAX_PER_PAGE, false);
                try {
                    $query = $this->getBatchedQuery($this->mediaType . '-relations', $pageChunks, $page);

                    $response = AniListClient::request($query['query'], $query['vars']);
                    foreach ($response['data'] as $p) {
                        foreach ($p[$key] as $row) {
                            $this->handleRelationalData($row);
                        }
                    }
                    $this->progressBar->advance();
                } catch (Throwable $e) {
                    // Do nothing, continue the loop. We. are. not. finished.
                    $this->handleException($e);
                }
            }

            $this->progressBar->finish();
            $this->output->write(PHP_EOL);
            ++$page;
        }

        $this->output->writeln('Writing relational data to file.');
        file_put_contents($filename, json_encode($this->relationalData));

        $this->output->writeln('Done.');
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
            if (\count($edge['voiceActors']) > 0) {
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
                $this->relationalData[$id][$edge['id']] = [
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
            $this->relationalData[$id][$edge['id']] = [
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
            $this->relationalData[$id][$edge['id']] = [
                'related_media_id' => $edge['node']['id'],
                'relation_type' => $edge['relationType'],
            ];
        }

        return $data['pageInfo']['hasNextPage'];
    }
}
