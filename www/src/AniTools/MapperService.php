<?php

declare(strict_types=1);

namespace AniTools;

use AniTools\Util\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\QueryBuilder;
use Meilisearch\Client;
use Monolog\Logger;

final class MapperService
{
    public function __construct(
        private Connection $db,
        private Logger $log,
        private Client $meili,
        private APIService $apiService
    ) {
    }

    public function prefilterUnmappableData(): void
    {
        // Delete existing prefiltered entries
        $this->db->delete('mapping_votes', ['voted_by' => 0]);

        // First we get all unmapped manga
        $sel = $this->db->createQueryBuilder();
        $sel->select('media.id', 'media.title_native', 'media.title_romaji');
        $sel->from('media');
        $sub = $this->db->createQueryBuilder();
        $sub->select('media_id');
        $sub->from('media_external_ids');
        $sub->where(
            $sub->expr()->in('source', ["'AniTools'", "'Animeshon'", "'MangaDex'"]),
            $sub->expr()->eq('service', "'MangaUpdates'"),
        );
        $sel->where(
            $sel->expr()->eq('media.media_type', "'MANGA'"),
            "media.id not in ($sub)",
        );
        $sel->orderBy('media.id');
        $this->log->debug((string) $sel);

        $results = $sel->executeQuery()->fetchAllAssociativeIndexed();

        $searchParams = [
            'attributesToRetrieve' => ['id'],
            'limit' => 10,
        ];

        $notFound = [];
        foreach ($results as $alId => $titles) {
            $searchTitle = $titles['title_native'] ?? $titles['title_romaji'];
            $this->log->debug('Searching Meili for "' . $searchTitle . '"');
            $meiliResult = $this->meili->getIndex('mangaupdates')->search($searchTitle, $searchParams);
            if ($meiliResult->getHitsCount() === 0) {
                $notFound[] = $alId;
            }
        }

        $u = new User();
        $u->id = 0;
        $u->userName = 'AniTools';
        foreach ($notFound as $alId) {
            $this->createMapping($alId, null, $u);
        }
    }

    private function getBaseQuery(User $user): QueryBuilder
    {
        $sel = $this->db->createQueryBuilder();
        $sel->select('media.*');
        $sel->from('media');

        $sub = $this->db->createQueryBuilder();
        $sub->select('media_id');
        $sub->from('media_external_ids');
        $sub->where(
                $sub->expr()->in('source', ["'AniTools'", "'Animeshon'", "'MangaDex'"]),
            $sub->expr()->eq('service', "'MangaUpdates'"),
        );

        // We need to make sure that users don't get entries they've already voted for
        $sub2 = $this->db->createQueryBuilder();
        $sub2->select('media_id');
        $sub2->from('mapping_votes');
        $sub2->where(
            $sub2->expr()->eq('voted_by', (string) $user->id),
        );

        // Subselect to get the amounts of existing votes as well as "not found" votes for each entry to prevent the
        // same entries users already voted against from reappearing
        $sub3 = $this->db->createQueryBuilder();
        $sub3->select(
            'media_id',
            'count(*) FILTER(WHERE mangaupdates_id IS NULL) as unmappable_votes',
            'count(*) FILTER(WHERE mangaupdates_id IS NOT NULL AND voted_by <> ' . $user->id . ') as already_voted',
        );
        $sub3->from('mapping_votes');
        //$sub3->where($sub3->expr()->isNull('mangaupdates_id'));
        $sub3->groupBy('media_id');
        $sel->leftJoin('media', '(' . (string) $sub3 . ')', 'vote_counts', 'vote_counts.media_id = media.id');

        $sel->where(
            $sel->expr()->eq('media.media_type', "'MANGA'"),
            "media.id not in ($sub)",
            "media.id not in ($sub2)",
        );

        return $sel;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    private function getRandomEntryAndResults(User $user, array $filters): array
    {
        $timings = [];
        $tStart = microtime(true);
        $sel = $this->getBaseQuery($user);

        $whereClauses = $this->apiService->getWhereClauses($filters, $sel, $user->userName);
        $sel->andWhere(...$whereClauses);

        $sel->addOrderBy('vote_counts.already_voted', 'desc nulls last');
        $sel->addOrderBy('vote_counts.unmappable_votes', 'asc nulls first');
        $sel->addOrderBy('random()');
        $sel->setMaxResults(1);
        $this->log->debug((string) $sel, ['username' => '(' . ($user->userName) . ') ']);

        $randomEntry = $sel->executeQuery()->fetchAssociative();
        $timings[] = 'db-al-entry;dur=' . ((microtime(true) - $tStart) * 1000);
        $tStart = microtime(true);

        // If there's no result, short-circuit
        if ($randomEntry === false) {
            throw new \InvalidArgumentException('Couldn\'t find any unmapped manga with the given filters');
        }

        $randomEntry['genres'] = $randomEntry['genres'] ? json_decode($randomEntry['genres'], true) : [];
        $randomEntry['tags'] = $randomEntry['tags'] ? json_decode($randomEntry['tags'], true) : [];

        // Search for existing votes (including votes for MU entries that Meili didn't list)
        $sel = $this->db->createQueryBuilder();
        $sel->select('mangaupdates_id', 'jsonb_agg(u.user_name) as voters');
        $sel->from('mapping_votes');
        $sel->join('mapping_votes', '"user"', 'u', 'u.id = mapping_votes.voted_by');
        $sel->where(
            $sel->expr()->eq('media_id', (string) $randomEntry['id']),
            $sel->expr()->neq('voted_by', (string) $user->id),
            $sel->expr()->isNotNull('mangaupdates_id'),
        );
        $sel->groupBy('mangaupdates_id');
        $sel->orderBy('count(*)', 'desc');

        // Put IDs of voted entries in front of the IDs from the search on Meili
        $suggestions = [];
        $this->log->debug((string) $sel, ['username' => '(' . ($user->userName) . ') ']);
        $votes = $sel->executeQuery()->fetchAllAssociative();
        $timings[] = 'db-existing-votes;dur=' . ((microtime(true) - $tStart) * 1000);
        $tStart = microtime(true);
        foreach ($votes as $vote) {
            $suggestions[$vote['mangaupdates_id']] = [
                'voted' => true,
                'score' => 2,
                'voters' => $vote['voters'],
            ];
        }

        $searchParams = [
            'attributesToRetrieve' => ['id'],
            'showRankingScore' => true,
            'limit' => 10,
        ];

        $searchTitle = $randomEntry['title_native'] ?? $randomEntry['title_romaji'];
        // Mangaupdates usually has "(Novel)" behind it's title in case of LNs.
        // This should make sure that the LN is on first position in case both exist
        if ($randomEntry['format'] === 'NOVEL') {
            $searchTitle .= ' Novel';
        }
        $this->log->debug('Searching Meili for "' . $searchTitle . '"', ['username' => '(' . ($user->userName) . ') ']);
        $meiliResult = $this->meili->getIndex('mangaupdates')->search($searchTitle, $searchParams);
        $this->log->debug(
            'Meili found ' . $meiliResult->count() . ' results',
            ['username' => '(' . ($user->userName) . ') '],
        );

        // If the native search yielded 0 results, try romaji instead
        if ($meiliResult->getHitsCount() === 0 && $searchTitle === $randomEntry['title_native']) {
            $this->log->debug(
                'Searching Meili for "' . $randomEntry['title_romaji'] . '"',
                ['username' => '(' . ($user->userName) . ') '],
            );
            $meiliResult = $this->meili->getIndex('mangaupdates')->search($randomEntry['title_romaji'], $searchParams);
            $timings[] = 'meili-search;dur=' . ((microtime(true) - $tStart) * 1000);
            $tStart = microtime(true);
            $this->log->debug(
                'Meili found ' . $meiliResult->count() . ' results',
                ['username' => '(' . ($user->userName) . ') '],
            );
        }

        foreach ($meiliResult->getHits() as $result) {
            // Filter out any results with a low score
            if ($result['_rankingScore'] < 0.55) {
                continue;
            }

            if (isset($suggestions[$result['id']])) {
                $suggestions[$result['id']]['score'] = $result['_rankingScore'];
            } else {
                $suggestions[$result['id']] = [
                    'score' => $result['_rankingScore'],
                ];
            }
        }

        return [
            'al_entry' => $randomEntry,
            'suggestions' => $suggestions,
            'timings' => $timings,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, mixed>
     */
    public function getMappingSuggestion(User $user, array $filters): array
    {
        $output = [
            'al_entry' => [],
            'suggestions' => [],
            'stats' => $this->getStats($user, $filters),
            'timings' => [],
        ];
        $output['timings'] = $output['stats']['timings'];

        try {
            $result = $this->getRandomEntryAndResults($user, $filters);
            if (\count($result['suggestions']) === 0) {
                $output['error'] = 'The search for the titles against the MU data didn\'t return any results. '
                 . 'Please click the "None found" button or try finding it yourself.';
            }
        } catch (\InvalidArgumentException $e) {
            $output['error'] = $e->getMessage();

            return $output;
        }

        $output['timings'] = array_merge($output['timings'], $result['timings']);
        $tStart = microtime(true);

        // Get the staff for the AL entry
        $sel = $this->db->createQueryBuilder();
        $sel->select("ms.media_id", "staff.name_last", "staff.name_first", "ms.\"role\"");
        $sel->from('staff');
        $sel->innerJoin('staff', 'media_staff', 'ms', 'staff.id = ms.staff_id');
        $sel->where($sel->expr()->eq('ms.media_id', (string) $result['al_entry']['id']));
        $this->log->debug((string) $sel, ['username' => '(' . ($user->userName) . ') ']);
        $staffResult = $sel->executeQuery()->fetchAllAssociative();
        $output['timings'][] = 'db-al-staff;dur=' . ((microtime(true) - $tStart) * 1000);
        $tStart = microtime(true);

        $alEntry = $result['al_entry'];
        $alEntry['authors'] = $staffResult;

        $suggestions = [];
        if (\count($result['suggestions']) > 0) {
            // Get the mangaupdates rows meili returned
            $sel = $this->db->createQueryBuilder();
            $sel->select('*');
            $sel->from('mangaupdates');
            $sel->where($sel->expr()->in('id', array_keys($result['suggestions'])));
            $this->log->debug((string) $sel, ['username' => '(' . ($user->userName) . ') ']);
            $results = $sel->executeQuery()->fetchAllAssociativeIndexed();
            $output['timings'][] = 'db-mu-entries;dur=' . ((microtime(true) - $tStart) * 1000);

            // Reorder results by original order from Meilisearch
            foreach ($result['suggestions'] as $id => $suggestion) {
                $results[$id]['id'] = $id;
                $results[$id]['voted'] = $suggestion['voted'] ?? false;
                $results[$id]['score'] = $suggestion['score'] ?? 0;
                $results[$id]['voters'] = isset($suggestion['voters']) ? json_decode($suggestion['voters'], true) : [];
                $results[$id]['titles'] = $results[$id]['titles'] ? json_decode($results[$id]['titles'], true) : [];
                $results[$id]['genres'] = $results[$id]['genres'] ? json_decode($results[$id]['genres'], true) : [];
                $results[$id]['categories'] = $results[$id]['categories']
                    ? json_decode($results[$id]['categories'], true)
                    : [];
                $results[$id]['authors'] = $results[$id]['authors'] ? json_decode($results[$id]['authors'], true) : [];
                $results[$id]['publishers'] = $results[$id]['publishers']
                    ? json_decode($results[$id]['publishers'], true)
                    : [];
                $results[$id]['publications'] = $results[$id]['publications']
                    ? json_decode($results[$id]['publications'], true)
                    : [];
                $suggestions[] = $results[$id];
            }
        }

        $output['suggestions'] = $suggestions;
        $output['al_entry'] = $alEntry;

        return $output;
    }

    /** @param null | array<int, int> $muIds */
    public function createMapping(int $alId, ?array $muIds, User $user): void
    {
        // Making sure nobody submits the same muIds twice
        if ($muIds !== null) {
            $muIds = array_unique($muIds);
        }

        $ins = $this->db->createQueryBuilder();
        // I know what i'm doing so my mappings directly go live ( ͡° ͜ʖ ͡°)
        if ($muIds !== null && $user->id === 124340) {
            $ins->insert('media_external_ids');
            foreach ($muIds as $muId) {
                $ins->values([
                    'media_id' => $alId,
                    'external_id' => $muId,
                    'service' => "'MangaUpdates'",
                    'source' => "'AniTools'",
                ]);

                $this->log->debug((string) $ins, ['username' => '(' . ($user->userName) . ') ']);
                $ins->executeQuery();
            }
        } else {
            $ins->insert('mapping_votes');
            // None found vote
            if ($muIds === null) {
                $ins->values([
                    'media_id' => $alId,
                    'mangaupdates_id' => 'null',
                    'voted_by' => $user->id,
                ]);
                $this->log->debug((string) $ins, ['username' => '(' . ($user->userName) . ') ']);
                $ins->executeQuery();
            } else {
                foreach ($muIds as $muId) {
                    $ins->values([
                        'media_id' => $alId,
                        'mangaupdates_id' => $muId,
                        'voted_by' => $user->id,
                        'is_multivote' => \count($muIds) > 1,
                    ]);
                    $this->log->debug((string) $ins, ['username' => '(' . ($user->userName) . ') ']);
                    $ins->executeQuery();
                }
            }
        }
        // Increase the user's mapping vote count
        $upd = $this->db->createQueryBuilder();
        $upd->update('"user"');
        $upd->set('mapping_votes', 'mapping_votes + 1');
        $upd->where($upd->expr()->eq('id', (string) $user->id));
        $this->log->debug((string) $upd, ['username' => '(' . ($user->userName) . ') ']);
        $upd->executeQuery();
    }

    /** @return array<string, mixed> */
    public function getMangaUpdatesInfoFor(int $id): array
    {
        $sel = $this->db->createQueryBuilder();
        $sel->select('*');
        $sel->from('mangaupdates');
        $sel->where($sel->expr()->eq('id', (string) $id));
        $this->log->debug((string) $sel);
        $result = $sel->executeQuery()->fetchAssociative();

        // Manga isn't present in database, manually import it
        if ($result === false) {
            try {
                /** @var MangaUpdatesSeriesInfo */
                $response = MangaUpdatesClient::request('series/' . $id);
                $data = [
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

                // Write data into json file
                $manuallyImported = [];
                if (file_exists(self::MANUAL_MANGAUPDATES_IMPORTS_FILE)) {
                    $manuallyImported = json_decode(file_get_contents(self::MANUAL_MANGAUPDATES_IMPORTS_FILE));
                }
                $manuallyImported[$response['series_id']] = $data;

                file_put_contents(
                    self::MANUAL_MANGAUPDATES_IMPORTS_FILE,
                    json_encode($manuallyImported)
                );

                // Prepare data for insert into db
                $data['id'] = $response['series_id'];
                $data['titles'] = json_encode($data['titles'], JSON_UNESCAPED_UNICODE);
                $data['genres'] = json_encode($data['genres'], JSON_UNESCAPED_UNICODE);
                $data['categories'] = json_encode($data['categories'], JSON_UNESCAPED_UNICODE);
                $data['authors'] = json_encode($data['authors'], JSON_UNESCAPED_UNICODE);
                $data['publishers'] = json_encode($data['publishers'], JSON_UNESCAPED_UNICODE);
                $data['publications'] = json_encode($data['publications'], JSON_UNESCAPED_UNICODE);
                $data['last_updated'] = date('Y-m-d H:i:s', $data['last_updated']);

                // Import into db
                $ins = DBService::getBatchInsertFor('mangaupdates', [$data], 'mangaupdates_pk', true);
                $this->log->debug((string) $ins);
                $this->db->executeQuery($ins);

                // Fetch again
                $this->log->debug((string) $sel);
                $result = $sel->executeQuery()->fetchAssociative();

            } catch (RequestException $e) {
                // Series got deleted
                if ($e->getResponse()->getStatusCode() === 404) {
                    throw new \UnexpectedValueException(
                        'MangaUpdates reported that it couldn\'t find the given manga.'
                    );
                } else {
            throw new \UnexpectedValueException(
                        'MangaUpdates entry could not be loaded due to an unknown error. Please try again later.'
            );
                }
            }
        }

        $result['titles'] = $result['titles'] ? json_decode($result['titles'], true) : [];
        $result['genres'] = $result['genres'] ? json_decode($result['genres'], true) : [];
        $result['categories'] = $result['categories'] ? json_decode($result['categories'], true) : [];
        $result['authors'] = $result['authors'] ? json_decode($result['authors'], true) : [];
        $result['publishers'] = $result['publishers'] ? json_decode($result['publishers'], true) : [];
        $result['publications'] = $result['publications'] ? json_decode($result['publications'], true) : [];

        return $result;
    }

    /**
     * @param array<string, mixed> $filters
     * @return array<string, int>
     */
    public function getStats(User $user, array $filters): array
    {
        $timings = [];
        $tStart = microtime(true);
        $stats = [];
        $sel = $this->db->createQueryBuilder();
        $sel->select(
            'mei.media_id is not null as isMapped',
            'count(*) as amount',
        );
        $sel->from('media');

        $sub = $this->db->createQueryBuilder();
        $sub->select('DISTINCT media_id');
        $sub->from('media_external_ids');
        $sub->where($sub->expr()->eq('service', "'MangaUpdates'"));

        $sel->leftJoin('media', '(' . $sub . ')', 'mei', 'media.id = mei.media_id');
        $sel->where($sel->expr()->eq('media.media_type', "'MANGA'"));
        $sel->groupBy('mei.media_id is not null');

        $this->log->debug((string) $sel, ['username' => '(' . ($user->userName) . ') ']);
        $result = $sel->executeQuery()->fetchAllAssociativeIndexed();
        $timings[] = 'db-stats-1;dur=' . ((microtime(true) - $tStart) * 1000);
        $tStart = microtime(true);

        $stats['total_manga'] = $result[true]['amount'] + $result[false]['amount'];
        $stats['total_unmapped'] = $result[false]['amount'];
        $stats['total_mapped'] = $result[true]['amount'];

        $sel = $this->getBaseQuery($user);
        $sel->select('count(*) as amount');
        $this->log->debug((string) $sel, ['username' => '(' . ($user->userName) . ') ']);
        $result = $sel->executeQuery()->fetchAllAssociative();
        $stats['total_unvoted'] = $result[0]['amount'];
        $timings[] = 'db-stats-2;dur=' . ((microtime(true) - $tStart) * 1000);
        $tStart = microtime(true);

        $whereClauses = $this->apiService->getWhereClauses($filters, $sel, $user->userName);
        $sel->andWhere(...$whereClauses);

        $this->log->debug((string) $sel, ['username' => '(' . ($user->userName) . ') ']);
        $result = $sel->executeQuery()->fetchAllAssociative();
        $timings[] = 'db-stats-3;dur=' . ((microtime(true) - $tStart) * 1000);

        $stats['total_unvoted_filtered'] = $result[0]['amount'];
        $stats['timings'] = $timings;

        return $stats;
    }
}
