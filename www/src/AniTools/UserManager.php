<?php

declare(strict_types=1);

namespace AniTools;

use AniTools\Util\User;
use Cocur\Slugify\Slugify;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\DBAL\Query\QueryBuilder;
use Monolog\Logger;

/** @phpstan-type ALUserMedia array{
 *   media: array{
 *     id: int
 *   },
 *   notes: string | null,
 *   status: 'CURRENT' | 'PLANNING' | 'COMPLETED' | 'DROPPED' | 'PAUSED' | 'REPEATING',
 *   progress: int | null,
 *   progressVolumes: int | null,
 *   score: int | float | null,
 *   repeat: int,
 *   startedAt: array{
 *      year: int | null,
 *      month: int | null,
 *      day: int | null
 *   },
 *   completedAt: array{
 *      year: int | null,
 *      month: int | null,
 *      day: int | null
 *   },
 *   hiddenFromStatusLists: bool,
 *   createdAt: int,
 *   updatedAt: int,
 *   private: bool
 * }
 */
final class UserManager
{
    private Connection $db;

    private Slugify $slugify;

    private Logger $log;

    private QueryBuilder $selectUser;
    private QueryBuilder $selectUserLists;
    private QueryBuilder $selectUserStatusDistribution;

    public function __construct(Connection $dbConnection, Logger $logger)
    {
        $this->log = $logger;
        $this->db = $dbConnection;
        $this->slugify = new Slugify();
        $this->prepareStatements();
    }

    /**
     * @return array<array<string, mixed>>
     */
    public function getUserLists(string $userName, string $mediaType): array
    {
        $qb = $this->db->createQueryBuilder();
        $sel = $qb->select('id');
        $sel->from('"user"');
        $sel->where($qb->expr()->eq('lower("user".user_name)', "'" . strtolower($userName) . "'"));

        $result = $sel->executeQuery();
        if ($result->rowCount() === 0) {
            return [];
        }
        $userId = $result->fetchAssociative()['id'];

        $this->selectUserLists->setParameter('user_id', $userId);
        $this->selectUserLists->setParameter('media_type', $mediaType);

        $tStart = microtime(true);
        $this->log->debug(
            ((string) $this->selectUserLists) . PHP_EOL .
            'Parameters: ' . json_encode($this->selectUserLists->getParameters(), JSON_PRETTY_PRINT),
            ['username' => '(' . $userName . ') ']
        );
        $results = $this->selectUserLists->executeQuery()->fetchAllAssociative();
        $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

        $this->selectUserStatusDistribution->setParameter('user_id', $userId);
        $this->selectUserStatusDistribution->setParameter('media_type', $mediaType);
        $tStart = microtime(true);
        $this->log->debug(
            ((string) $this->selectUserStatusDistribution) . PHP_EOL .
            'Parameters: ' . json_encode($this->selectUserStatusDistribution->getParameters(), JSON_PRETTY_PRINT),
            ['username' => '(' . $userName . ') ']
        );
        $statusDistribution = $this->selectUserStatusDistribution->executeQuery()->fetchAllKeyValue();
        $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

        if (array_key_exists('CURRENT', $statusDistribution)) {
            $results[] = [
                'id' => 'all-current',
                'name' => 'All Current',
                'is_custom_list' => 1,
                'amount_total' => $statusDistribution['CURRENT'],
            ];
        }
        if (array_key_exists('PLANNING', $statusDistribution)) {
            $results[] = [
                'id' => 'all-planning',
                'name' => 'All Planning',
                'is_custom_list' => 1,
                'amount_total' => $statusDistribution['PLANNING'],
            ];
        }
        if (array_key_exists('COMPLETED', $statusDistribution)) {
            $results[] = [
                'id' => 'all-completed',
                'name' => 'All Completed',
                'is_custom_list' => 1,
                'amount_total' => $statusDistribution['COMPLETED'],
            ];
        }
        if (array_key_exists('DROPPED', $statusDistribution)) {
            $results[] = [
                'id' => 'all-dropped',
                'name' => 'All Dropped',
                'is_custom_list' => 1,
                'amount_total' => $statusDistribution['DROPPED'],
            ];
        }
        if (array_key_exists('PAUSED', $statusDistribution)) {
            $results[] = [
                'id' => 'all-paused',
                'name' => 'All Paused',
                'is_custom_list' => 1,
                'amount_total' => $statusDistribution['PAUSED'],
            ];
        }
        if (array_key_exists('REPEATING', $statusDistribution)) {
            $results[] = [
                'id' => 'all-repeating',
                'name' => 'All Repeating',
                'is_custom_list' => 1,
                'amount_total' => $statusDistribution['REPEATING'],
            ];
        }
        $results[] = [
            'id' => 'all',
            'name' => 'All',
            'is_custom_list' => 1,
            'amount_total' => array_sum($statusDistribution),
        ];

        return $results;
    }

    /** @param array<string, mixed> $data */
    public function importUser(array $data, string $mediaType): void
    {
        $this->selectUser->setParameter('id', $data['user']['id']);
        $tStart = microtime(true);
        $this->log->debug(
            ((string) $this->selectUser) . PHP_EOL .
            'Parameters: ' . json_encode($this->selectUser->getParameters(), JSON_PRETTY_PRINT),
            ['username' => '(' . $data['user']['name'] . ') ']
        );
        $result = $this->selectUser->executeQuery()->fetchAssociative();
        $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');
        if ($result !== false) {
            $this->log->debug("Reimporting user " . $data['user']['name'] . " (ID: " . $data['user']['id'] . ")");
            // Update username if it changed
            if ($data['user']['name'] !== $result['user_name']) {
                // Delete user that had the name before if it exists since they have been inactive for a long time
                // anyways to have their name taken away
                $this->db->delete(
                    '"user"',
                    ['user_name' => $data['user']['name']],
                );
                // Update user that has the name now
                $this->db->update(
                    '"user"',
                    ['user_name' => $data['user']['name']],
                    ['id' => $data['user']['id']],
                );
            }

            // Clear lists for reimport
            $this->db->delete('user_lists', ['user_id' => $data['user']['id'], 'media_type' => $mediaType]);
            // Delete user_media entries for the media type
            $del = $this->db->createQueryBuilder();
            $del->delete('user_media');
            $sub = $this->db->createQueryBuilder();
            $sub->select('media_id');
            $sub->from('user_media');
            $sub->innerJoin('user_media', 'media', 'm', 'm.id = user_media.media_id');
            $sub->where(
                $sub->expr()->eq('user_id', (string) $data['user']['id']),
                $sub->expr()->eq('m.media_type', "'" . $mediaType . "'"),
            );
            $del->where(
                $del->expr()->in('media_id', (string) $sub),
                $del->expr()->eq('user_id', (string) $data['user']['id']),
            );
            $del->executeQuery();
        } else {
            $this->log->debug("Importing user " . $data['user']['name'] . " (ID: " . $data['user']['id'] . ")");
            $this->db->insert('"user"', [
                'id' => $data['user']['id'],
                'user_name' => $data['user']['name'],
            ]);
        }

        $listOrder = $data['user']['mediaListOptions'][strtolower($mediaType) . 'List']['sectionOrder'];

        $mediaListInsValues = [];
        $mediaInsValues = [];
        $this->log->debug("Inserting " . \count($data['lists']) . " lists");
        foreach ($data['lists'] as $list) {
            $position = array_search($list['name'], $listOrder, true);
            // The AniList API does not add newly added lists to the order unless it's been saved after it's creation
            if ($position === false) {
                $position = \count($listOrder);
                $listOrder[] = $list['name'];
            }

            $this->db->insert(
                'user_lists',
                [
                    'slug' => $this->slugify->slugify(
                        $data['user']['id'] . '-' . substr($mediaType, 0, 1) . '-' . $position
                    ),
                    'user_id' => $data['user']['id'],
                    'name' => $list['name'],
                    'is_custom_list' => (int) $list['isCustomList'],
                    'media_type' => $mediaType,
                    'position' => $position,
                ],
            );
            $listId = $this->db->lastInsertId();

            /** @var ALUserMedia $media */
            foreach ($list['entries'] as $media) {
                $mediaListInsValues[] = [
                    'user_id' => $data['user']['id'],
                    'list_id' => $listId,
                    'media_id' => $media['media']['id'],
                ];

                $startedAt = $media['startedAt']['year'] . '-'
                    . $media['startedAt']['month'] . '-'
                    . $media['startedAt']['day'];
                if ($media['startedAt']['year'] === null) {
                    $startedAt = null;
                }
                $completedAt = $media['completedAt']['year'] . '-'
                    . $media['completedAt']['month'] . '-'
                    . $media['completedAt']['day'];
                if ($media['completedAt']['year'] === null) {
                    $completedAt = null;
                }
                $mediaInsValues[$media['media']['id']] = [
                    'user_id' => $data['user']['id'],
                    'media_id' => $media['media']['id'],
                    'notes' => $media['notes'],
                    'status' => $media['status'],
                    'progress' => $media['progress'],
                    'progress_volumes' => $media['progressVolumes'] ?? 0,
                    'score' => $media['score'],
                    'repeat' => $media['repeat'],
                    'started_at' => $startedAt,
                    'completed_at' => $completedAt,
                    'hidden_from_status_lists' => $media['hiddenFromStatusLists'] ? 'true' : 'false',
                    'created_at' => $media['createdAt'],
                    'updated_at' => $media['updatedAt'],
                    'is_private' => $media['private'] ? 'true' : 'false',
                ];
            }
        }

        $this->log->debug('Inserting ' . \count($mediaListInsValues) . ' media-list-links.');
        $chunks = array_chunk($mediaListInsValues, 500);

        try {
            foreach ($chunks as $chunk) {
                $this->db->beginTransaction();
                $ins = 'INSERT INTO user_media_list VALUES ';
                $values = [];
                foreach ($chunk as $row) {
                    $values[] = '(' . implode(',', $row) . ')';
                }
                $this->db->executeQuery($ins . implode(',', $values));
                $this->db->commit();
            }

            $this->log->debug('Inserting ' . \count($mediaInsValues) . ' media-list-entries.');
            $chunks = array_chunk($mediaInsValues, 10);

            foreach ($chunks as $chunk) {
                $this->db->beginTransaction();

                $ins = 'INSERT INTO user_media VALUES ';
                $values = [];
                foreach ($chunk as $row) {
                    $row = array_map(function ($v) {
                        if (is_string($v)) {
                            return "'" . str_replace("'", "''", $v) . "'";
                        }
                        if ($v === null) {
                            return 'null';
                        }
                        return $v;
                    }, $row);

                    $values[] = '(' . implode(',', $row) . ')';
                }
                $this->db->executeQuery($ins . implode(',', $values));
                $this->db->commit();
            }
        } catch (DriverException $e) {
            $this->db->rollBack();
            $this->log->debug($e->getQuery()->getSQL());
            throw $e;
        }
    }

    public function getUserById(int $id): User
    {
        $sel = $this->db->createQueryBuilder();
        $sel->select('user.id', 'user_name');
        $sel->from('"user"');
        $sel->where($sel->expr()->eq('id', (string) $id));

        $result = $sel->executeQuery();

        if ($result->rowCount() === 0) {
            throw new \UnexpectedValueException('User not found');
        }

        $result = $result->fetchAssociative();

        return new User($result['id'], $result['user_name']);
    }

    public function getUserByName(string $userName): User
    {
        $sel = $this->db->createQueryBuilder();
        $sel->select('id', 'user_name');
        $sel->from('"user"');
        $sel->where($sel->expr()->eq('lower(user_name)', $this->db->quote(strtolower($userName))));

        $result = $sel->executeQuery();

        if ($result->rowCount() === 0) {
            throw new \UnexpectedValueException('User not found');
        }

        $result = $result->fetchAssociative();

        return new User($result['id'], $result['user_name']);
    }

    private function prepareStatements(): void
    {
        $sel = $this->db->createQueryBuilder();
        $sel->select('user_name');
        $sel->from('"user"');
        $sel->where($sel->expr()->eq('id', ':id'));
        $this->selectUser = $sel;

        $subSel = $this->db->createQueryBuilder();
        $whereUser = $subSel->expr()->eq('user_media_list.user_id', ':user_id');
        $subSel->select('user_media_list.list_id', 'COUNT(user_media_list.media_id) AS amount');
        $subSel->from('user_media_list');
        $subSel->where($whereUser);
        $subSel->groupBy('user_media_list.list_id');
        $totalSub = (string) $subSel;
        $subSel->innerJoin(
            'user_media_list',
            'user_media',
            'user_media',
            'user_media.media_id = user_media_list.media_id AND user_media.user_id = user_media_list.user_id'
        );
        $subSel->where($whereUser, $subSel->expr()->eq('user_media.status', "'COMPLETED'"));
        $completedSub = (string) $subSel;

        $sel = $this->db->createQueryBuilder();
        $sel->select(
            'user_lists.slug AS id',
            'user_lists.name',
            'is_custom_list',
            'COALESCE(total_media.amount, 0) AS amount_total',
            'COALESCE(completed_media.amount, 0) AS amount_completed',
        );
        $sel->from('user_lists');
        $sel->innerJoin('user_lists', "($totalSub)", 'total_media', 'total_media.list_id = user_lists.id');
        $sel->leftJoin('user_lists', "($completedSub)", 'completed_media', 'completed_media.list_id = user_lists.id');
        $sel->where(
            $sel->expr()->eq('user_lists.user_id', ':user_id'),
            $sel->expr()->eq('user_lists.media_type', ':media_type'),
        );
        $sel->orderBy('position', 'ASC');
        $this->selectUserLists = $sel;

        $sel = $this->db->createQueryBuilder();
        $sel->select('user_media.status', 'COUNT(media_id) AS amount');
        $sel->from('user_media');
        $sel->join('user_media', 'media', 'media', 'media.id =  user_media.media_id');
        $sel->where(
            $sel->expr()->eq('user_media.user_id', ':user_id'),
            $sel->expr()->eq('media.media_type', ':media_type'),
        );
        $sel->groupBy('user_media.status');
        $this->selectUserStatusDistribution = $sel;
    }
}
