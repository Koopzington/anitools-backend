<?php

declare(strict_types=1);

namespace AniTools;

use AniTools\Util\AniListClient;
use AniTools\Util\IntRange;
use AniTools\Util\MediaType;
use AniTools\Util\RegEx;
use AniTools\Util\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Query\Expression\CompositeExpression;
use Doctrine\DBAL\Query\QueryBuilder;
use InvalidArgumentException;
use Monolog\Logger;

final class APIService
{
    private Connection $db;

    private DBService $dbService;

    private Logger $log;

    /** @var array<string, array<string, int>> */
    private array $totals = [];

    /** @var array<string, array<string, mixed[]>> */
    private array $filterValueCache = [];

    private const COLUMN_MAP = [
        'title' => 'title_romaji',
        'titleEng' => 'COALESCE(title_english, title_romaji)',
        'titleNat' => 'title_native',
        'id' => 'id',
        'season' => 'season',
        'year' => 'media.start_date_y',
        'seasonYear' => 'CONCAT_WS(\' \', season_year, season)',
        'airStart' => 'CONCAT_WS(\'-\', start_date_y,'
            . ' LPAD(start_date_m::VARCHAR, 2, \'0\'), LPAD(start_date_d::VARCHAR, 2, \'0\'))',
        'airEnd' => 'CONCAT_WS(\'-\', end_date_y,'
            . ' LPAD(end_date_m::VARCHAR, 2, \'0\'), LPAD(end_date_d::VARCHAR, 2, \'0\'))',
        'airStatus' => 'media.status',
        'format' => 'format',
        'country' => 'country_of_origin',
        'episodes' => 'episodes',
        'volumes' => 'volumes',
        'duration' => 'duration',
        'totalDuration' => 'total_duration',
        'source' => 'source',
        'genres' => 'media.genres',
        'genreCount' => 'jsonb_array_length(media.genres)',
        'tags' => 'media.tags',
        'tagCount' => 'jsonb_array_length(media.tags)',
        'externalLinks' => 'media.external_links',
        'studios' => 'media.studios',
        'producers' => 'media.producers',
        'references' => 'uml.references',
        'avgScore' => 'average_score',
        'meanScore' => 'mean_score',
        'popularity' => 'popularity',
        'favourites' => 'favourites',
        'hasReview' => 'reviews > 0',
        'isAdult' => 'is_adult',
        'statusCurrent' => 'status_current',
        'statusPlanning' => 'status_planning',
        'statusCompleted' => 'status_completed',
        'statusDropped' => 'status_dropped',
        'statusPaused' => 'status_paused',
        'status' => 'user_media.status',
        'progress' => 'user_media.progress',
        'progressVolumes' => 'user_media.progress_volumes',
        'repeat' => 'user_media.repeat',
        'started' => 'user_media.started_at',
        'completed' => 'user_media.completed_at',
        'remaining' => '(episodes - user_media.progress)',
        'daysSpent' => '(user_media.completed_at - user_media.started_at) + 1',
        'score' => 'user_media.score',
        'notes' => 'user_media.notes',
        // Staff and Characters
        'nameFirst' => 'name_first',
        'nameMiddle' => 'name_middle',
        'nameLast' => 'name_last',
        'nameFull' => 'name_full',
        'nameNative' => 'name_native',
        'nameAlternatives' => 'name_alternatives',
        'nameAlternativesSpoiler' => 'name_alternatives_spoiler',
        'gender' => 'gender',
        'dateOfBirth' => 'CONCAT_WS(\'-\', date_of_birth_y,'
            . ' LPAD(date_of_birth_m::VARCHAR, 2, \'0\'), LPAD(date_of_birth_d::VARCHAR, 2, \'0\'))',
        'dateOfDeath' => 'CONCAT_WS(\'-\', date_of_death_y,'
            . ' LPAD(date_of_death_m::VARCHAR, 2, \'0\'), LPAD(date_of_death_d::VARCHAR, 2, \'0\'))',
        'bloodType' => 'blood_type',
        'homeTown' => 'home_town',
        'primaryOccupations' => 'primary_occupations',
        'age' => 'age',
        'yearsActiveFrom' => 'years_active_from',
        'yearsActiveUntil' => 'years_active_until',
        'appearances' => 'appearances.amount',
    ];

    // Contains optional mappings to how things are supposed to get ordered for specific columns
    private const ORDER_MAP = [
        'seasonYear' => [
            'season_year',
            // Supposedly databases should order ENUMs by the order the values were entered but this doesn't seem to be
            // the case. Manually telling the database how the sorting should look like
            "CASE season WHEN 'WINTER' THEN 1 WHEN 'SPRING' THEN 2 WHEN 'SUMMER' THEN 3 WHEN 'FALL' THEN 4 END",
        ],
        'season' => [
            "CASE season WHEN 'WINTER' THEN 1 WHEN 'SPRING' THEN 2 WHEN 'SUMMER' THEN 3 WHEN 'FALL' THEN 4 END",
        ],
        'references' => 'references',
    ];

    public function __construct(Connection $db, DBService $dBService, Logger $logger)
    {
        $this->db = $db;
        $this->dbService = $dBService;
        $this->log = $logger;

        $sel = $db->createQueryBuilder()
            ->select(
                'COUNT(media.id) as count',
                'SUM(media.episodes) as episodes',
                'SUM(media.volumes) as volumes',
                'SUM(media.episodes * media.duration) as runtime',
                'media.media_type'
            )
            ->from('media')
            ->groupBy('media.media_type');
        $this->log->debug((string) $sel);
        $resultset = $sel->executeQuery()->fetchAllAssociative();
        foreach ($resultset as $row) {
            $this->totals[$row['media_type']] = $row;
        }

        $sel = $db->createQueryBuilder()
            ->select(
                'COUNT(characters.id) as count'
            )->from('characters');
        $this->log->debug((string) $sel);
        $row = $sel->executeQuery()->fetchAssociative();
        $this->totals['CHARACTER'] = $row;

        $sel = $db->createQueryBuilder()
            ->select(
                'COUNT(staff.id) as count'
            )->from('staff');
        $this->log->debug((string) $sel);
        $row = $sel->executeQuery()->fetchAssociative();
        $this->totals['STAFF'] = $row;
    }

    /** @return array<string, int> */
    public function getTotal(MediaType $mediaType): array
    {
        return $this->totals[$mediaType->name];
    }

    private const USER_LIST_QUERY = '
    query ($userName: String, $mediaType: MediaType) {
        MediaListCollection (userName: $userName, type: $mediaType) {
            user {
                id
                name
                mediaListOptions {
                    mangaList {
                        sectionOrder
                    }
                    animeList {
                        sectionOrder
                    }
                }
            }
            lists {
                isCustomList
                name
                entries {
                    notes
                    status
                    progress
                    progressVolumes
                    score
                    repeat
                    startedAt {
                        year
                        month
                        day
                    }
                    completedAt {
                        year
                        month
                        day
                    }
                    media {
                        id
                    }
                    hiddenFromStatusLists
                    createdAt
                    updatedAt
                    private
                }
            }
        }
    }';

    /**
     * @param array<string, mixed[]> $values
     * @return string[]
     */
    private function getSubClausesFor(QueryBuilder $qb, string $field, array $values): array
    {
        $where = [];

        if (array_key_exists('or', $values)) {
            $clone = clone $qb;
            $clone->andWhere($clone->expr()->in($field, array_map([$clone->expr(), 'literal'], $values['or'])));
            $where[] = "media.id in ($clone)";
        }

        if (array_key_exists('and', $values)) {
            foreach ($values['and'] as $v) {
                $clone = clone $qb;
                $clone->andWhere($clone->expr()->eq($field, $clone->expr()->literal((string) $v)));
                $where[] = "media.id in ($clone)";
            }
        }

        if (array_key_exists('not', $values)) {
            foreach ($values['not'] as $v) {
                $clone = clone $qb;
                $clone->andWhere($clone->expr()->eq($field, $clone->expr()->literal((string) $v)));
                $where[] = "media.id not in ($clone)";
            }
        }

        return $where;
    }

    /**
     * @param array<'and' | 'or' | 'not', array<string, mixed>> $values
     * @return CompositeExpression[] | string[]
     */
    private function getDirectSubClausesFor(QueryBuilder $qb, string $field, array $values): array
    {
        $where = [];

        if (array_key_exists('or', $values)) {
            $where[] = $qb->expr()->in($field, array_map([$qb->expr(), 'literal'], $values['or']));
        }

        if (array_key_exists('and', $values)) {
            foreach ($values['and'] as $v) {
                $where[] = $qb->expr()->eq($field, $qb->expr()->literal((string) $v));
            }
        }

        if (array_key_exists('not', $values)) {
            $where[] = $qb->expr()->notIn($field, array_map([$qb->expr(), 'literal'], $values['not']));
        }

        return $where;
    }

    /**
     * @param array<string, mixed[]> $values
     * @return array<string | CompositeExpression>
     */
    private function getJsonbSubClausesFor(
        string $field,
        string $accessor,
        array $values
    ): array {
        $tagPercentageMin = 0;
        if (isset($values['tagPercentageMin'])) {
            $tagPercentageMin = $values['tagPercentageMin'];
        }
        $tagPercentageMax = 100;
        if (isset($values['tagPercentageMax'])) {
            $tagPercentageMax = $values['tagPercentageMax'];
        }

        $where = [];

        if (array_key_exists('or', $values)) {
            $or = [];
            foreach ($values['or'] as $value) {
                if ($field === 'media.tags') {
                    $or[] = "jsonb_path_exists($field, '$[*] ? (@.tag == \"$value\" "
                    . "&& @.rank >= $tagPercentageMin && @.rank <= $tagPercentageMax)')";
                } else {
                    $or[] = "$field @@ '$accessor == \"$value\"'";
                }
            }
            $where[] = $this->db->createExpressionBuilder()->or(...$or);
        }

        if (array_key_exists('and', $values)) {
            foreach ($values['and'] as $value) {
                if ($field === 'media.tags') {
                    $where[] = "jsonb_path_exists($field, '$[*] ? (@.tag == \"$value\" "
                    . "&& @.rank >= $tagPercentageMin && @.rank <= $tagPercentageMax)')";
                } else {
                    $where[] = "$field @@ '$accessor == \"$value\"'";
                }
            }
        }

        if (array_key_exists('not', $values)) {
            foreach ($values['not'] as $value) {
                $where[] = "NOT($field @@ '$accessor == \"$value\"')";
            }
        }

        return $where;
    }

    private const FUZZYDATE_MAP = [
        'airingStart' => [
            'dir' => '>',
            'y' => 'start_date_y',
            'm' => 'start_date_m',
            'd' => 'start_date_d',
        ],
        'airingFinish' => [
            'dir' => '<',
            'y' => 'end_date_y',
            'm' => 'end_date_m',
            'd' => 'end_date_d',
        ],
        'birthdayFrom' => [
            'dir' => '>',
            'y' => 'date_of_birth_y',
            'm' => 'date_of_birth_m',
            'd' => 'date_of_birth_d',
        ],
        'birthdayUntil' => [
            'dir' => '<',
            'y' => 'date_of_birth_y',
            'm' => 'date_of_birth_m',
            'd' => 'date_of_birth_d',
        ],
        'deathdayFrom' => [
            'dir' => '>',
            'y' => 'date_of_death_y',
            'm' => 'date_of_death_m',
            'd' => 'date_of_death_d',
        ],
        'deathdayUntil' => [
            'dir' => '<',
            'y' => 'date_of_death_y',
            'm' => 'date_of_death_m',
            'd' => 'date_of_death_d',
        ],
        'userStartFrom' => [
            'dir' => '>',
            'col' => 'user_media.started_at',
        ],
        'userFinishUntil' => [
            'dir' => '<',
            'col' => 'user_media.completed_at',
        ],
    ];

    /**
     * @return CompositeExpression[] | string[]
     */
    private function getFuzzyDateClauses(string $filter, string $value, QueryBuilder $qb, ?string $userName): array
    {
        $where = [];
        $min = false;
        $max = false;

        if (strpos($filter, 'Min') !== false) {
            $min = true;
        }
        if (strpos($filter, 'Max') !== false) {
            $max = true;
        }
        // Cut the Min/Max off
        if ($min === true || $max === true) {
            $filter = substr($filter, 0, -3);
        }

        $mapped = self::FUZZYDATE_MAP[$filter];

        if (isset($mapped['col'])) {
            $mapped['y'] = "DATE_PART('year', " . $mapped['col'] . ")";
            $mapped['m'] = "DATE_PART('month', " . $mapped['col'] . ")";
            $mapped['d'] = "DATE_PART('day', " . $mapped['col'] . ")";
        }

        $split = explode('-', $value);
        if (\count($split) !== 3) {
            return $where;
        }
        list($year, $month, $day) = $split;
        if ($year === '*' || $month === '*' || $day === '*') {
            // Wildcards will turn the filter into pattern matching instead of a "greater than" filter
            if ($year !== '*') {
                $where[] = $qb->expr()->eq($mapped['y'], $year);
            }
            if ($month !== '*') {
                $where[] = $qb->expr()->eq($mapped['m'], $month);
            }
            if ($day !== '*') {
                $where[] = $qb->expr()->eq($mapped['d'], $day);
            }
        } else {
            // Check if date is valid
            if (! checkdate((int) $month, (int) $day, (int) $year)) {
                return $where;
            }
            
            $t = 'make_date(' . $mapped['y'] . ', coalesce(' . $mapped['m'] . ', 1), coalesce(' . $mapped['d']
                . ', 1))';

            // No need to build a date in the sql if the column has a date type
            if (isset($mapped['col'])) {
                $t = $mapped['col'];
            }

            if ($min === true) {
                $where[] = 'NOT(' . $qb->expr()->lte($t, "'$value'") . ')';
            } elseif ($max === true) {
                $where[] = 'NOT(' . $qb->expr()->gte($t, "'$value'") . ')';
            } elseif ($mapped['dir'] === '<') {
                $where[] = 'NOT(' . $qb->expr()->gte($t, "'$value'") . ')';
            } else {
                $where[] = 'NOT(' . $qb->expr()->lte($t, "'$value'") . ')';
            }
        }

        // In case of dates in user_media do a subquery instead
        if (isset($mapped['col']) && strpos($mapped['col'], 'user_media') === 0) {
            $sub = $this->db->createQueryBuilder();
            $sub->select('media_id');
            $sub->from('user_media');
            $sub->innerJoin('user_media', '"user"', '"user"', 'user_media.user_id = "user".id');
            $sub->where(
                $sub->expr()->eq('lower("user".user_name)', "'" . strtolower($userName) . "'"),
                ...$where
            );

            return [ "media.id in ($sub)" ];
        }

        return $where;
    }

    /**
     * @param 'media' | 'staff' | 'characters' $type
     * @param array<string, mixed> $filters
     * @param QueryBuilder $qb
     * @param string|null $userName
     * @return CompositeExpression[] | string[]
     */
    public function getWhereClauses(string $type, array $filters, QueryBuilder $qb, ?string $userName): array
    {
        $where = [];

        // These columns only contain values which makes it fairly easy to filter
        $valueCols = [
            'genre' => 'media.genres',
            'studio' => 'media.studios',
            'producer' => 'media.producers',
            'externalLink' => 'media.external_links',
            'primaryOccupation' => 'staff.primary_occupations',
        ];

        foreach ($filters as $key => $value) {
            if ($key === 'and') {
                $subClauses = $this->getWhereClauses($type, $value, $qb, $userName);
                if (\count($subClauses) > 0) {
                    $where[] = $qb->expr()->and(...$subClauses);
                }
            }
            if ($key === 'or') {
                $subClauses = $this->getWhereClauses($type, $value, $qb, $userName);
                if (\count($subClauses) > 0) {
                    $where[] = $qb->expr()->or(...$subClauses);
                }
            }

            if (isset($valueCols[$key])) {
                $where = array_merge($where, $this->getJsonbSubClausesFor($valueCols[$key], '$[*]', $value));
            }

            // Supports airingStart, airingStartMin and airingStartMax
            if (
                isset(self::FUZZYDATE_MAP[$key])
                || (
                    (strpos($key, 'Min') !== false || strpos($key, 'Max') !== false)
                    && isset(self::FUZZYDATE_MAP[substr($key, 0, -3)])
                )
            ) {
                $where = array_merge($where, $this->getFuzzyDateClauses($key, $value, $qb, $userName));
            }

            // ID
            if ($key === 'id') {
                if (isset($value['and'])) {
                    foreach ($value['and'] as $v) {
                        $where[] = $qb->expr()->like($type . '.id::text', "'%" . $v . "%'");
                    }
                }

                if (isset($value['or'])) {
                    $tmp = [];
                    foreach ($value['or'] as $v) {
                        $tmp[] = $qb->expr()->like($type . '.id::text', "'%" . $v . "%'");
                    }
                    $where[] = $qb->expr()->or(...$tmp);
                }

                if (isset($value['not'])) {
                    foreach ($value['not'] as $v) {
                        $where[] = $qb->expr()->notLike($type . '.id::text', "'%" . $v . "%'");
                    }
                }
            }

            // Title
            if ($key === 'titleLike') {
                if ($value['regex'] === true) {
                    try {
                        $rx = new RegEx($value['value']);
                    } catch (\Exception $e) {
                        // TODO: Throw exception instead and let user know
                        continue;
                    }
                    $pattern = $rx->postgresFormat;

                    $where[] = $qb->expr()->or(
                        "regexp_count(title_english, '$pattern') > 0",
                        "regexp_count(title_romaji, '$pattern') > 0",
                        "regexp_count(title_native, '$pattern') > 0",
                    );
                } else {
                    // Lowercase the string, split it by spaces and make the query needing to match all parts
                    $value = strtolower($value['value']);
                    // Check for quotes in the value, meant for explicit terms that may not be split by spaces
                    $quoteExp = explode('"', $value);
                    $explicitTerms = [];
                    $nonExplicitTerms = [];
                    if (\count($quoteExp) > 1) {
                        foreach ($quoteExp as $key => $part) {
                            if ($key % 2 === 0) {
                                $nonExplicitTerms[] = $part;
                            } else {
                                $explicitTerms[] = $part;
                            }
                        }

                        $value = implode(' ', array_filter($nonExplicitTerms));
                    }
                    
                    // Split by spaces
                    $parts = explode(' ', $value);
                    // Checks if only exlusion terms have been passed in which case the where clauses will make sure
                    // that none of the titles may contain them.
                    $onlyExclusion = \count(
                        array_filter($parts, function ($p) {
                            return strpos($p, '-') !== 0;
                        })
                    ) === 0;
                    if ($onlyExclusion && \count($explicitTerms) === 0) {
                        $ands = [];
                        foreach ($parts as $part) {
                            $part = substr($part, 1);
                            $ands[] = $qb->expr()->notLike("lower(coalesce(title_english, ''))", "'%$part%'");
                            $ands[] = $qb->expr()->notLike('lower(title_romaji)', "'%$part%'");
                            $ands[] = $qb->expr()->notLike("lower(coalesce(title_native, ''))", "'%$part%'");
                        }

                        $where[] = $qb->expr()->and(...$ands);
                    } else {
                        $ands = ['eng' => [], 'rom' => [], 'nat' => []];
                        foreach ($parts as $part) {
                            // Check for - in front of the part indicating that the title should NOT include it
                            if (strpos($part, '-') === 0 && $part !== '-') {
                                $part = substr($part, 1);
                                $ands['eng'][] = $qb->expr()->notLike(
                                    "lower(coalesce(title_english, ''))",
                                    "'%$part%'"
                                );
                                $ands['rom'][] = $qb->expr()->notLike('lower(title_romaji)', "'%$part%'");
                                $ands['nat'][] = $qb->expr()->notLike(
                                    "lower(coalesce(title_english, ''))",
                                    "'%$part%'"
                                );
                            } else {
                                $ands['eng'][] = $qb->expr()->like('lower(title_english)', "'%$part%'");
                                $ands['rom'][] = $qb->expr()->like('lower(title_romaji)', "'%$part%'");
                                $ands['nat'][] = $qb->expr()->like('lower(title_native)', "'%$part%'");
                            }
                        }

                        // Add clauses for explicit terms that can't be exclusions
                        foreach ($explicitTerms as $part) {
                            $ands['eng'][] = $qb->expr()->like('lower(title_english)', "'%$part%'");
                            $ands['rom'][] = $qb->expr()->like('lower(title_romaji)', "'%$part%'");
                            $ands['nat'][] = $qb->expr()->like('lower(title_native)', "'%$part%'");
                        }

                        $where[] = $qb->expr()->or(
                            $qb->expr()->and(...$ands['eng']),
                            $qb->expr()->and(...$ands['rom']),
                            $qb->expr()->and(...$ands['nat']),
                        );
                    }
                }
            }

            // Character/Staff name
            if ($key === 'nameLike') {
                if ($value['regex'] === true) {
                    try {
                        $rx = new RegEx($value['value']);
                    } catch (\Exception $e) {
                        // TODO: Throw exception instead and let user know
                        continue;
                    }
                    $pattern = $rx->postgresFormat;

                    $where[] = $qb->expr()->or(
                        "regexp_count(name_full, '$pattern') > 0",
                        "regexp_count(name_native, '$pattern') > 0",
                    );
                } else {
                    // Lowercase the string, split it by spaces and make the query needing to match all parts
                    $value = strtolower($value['value']);
                    $parts = explode(' ', $value);
                    $ands = ['full' => [], 'native' => []];
                    foreach ($parts as $part) {
                        $ands['full'][] = $qb->expr()->like('lower(name_full)', "'%$part%'");
                        $ands['native'][] = $qb->expr()->like('lower(name_native)', "'%$part%'");
                    }

                    $where[] = $qb->expr()->or(
                        $qb->expr()->and(...$ands['full']),
                        $qb->expr()->and(...$ands['native']),
                    );
                }
            }

            // Notes
            if ($key === 'notesLike') {
                // Lowercase the string, split it by spaces and make the query needing to match all parts
                $value = strtolower($value);
                $parts = explode(' ', $value);
                $ands = [];
                foreach ($parts as $part) {
                    $ands[] = $qb->expr()->like('lower(user_media.notes)', "'%$part%'");
                }
                $where[] = $qb->expr()->and(...$ands);
            }

            // Description
            if ($key === 'descriptionLike') {
                if ($value['regex'] === true) {
                    try {
                        $rx = new RegEx($value['value']);
                    } catch (\Exception $e) {
                        // TODO: Throw exception instead and let user know
                        continue;
                    }
                    $pattern = $rx->postgresFormat;
                    $where[] = "regexp_count(" . $type . ".description, '$pattern') > 0";
                } else {
                    // Lowercase the string, split it by spaces and make the query needing to match all parts
                    $value = strtolower($value['value']);
                    // Check for quotes in the value, meant for explicit terms that may not be split by spaces
                    $quoteExp = explode('"', $value);
                    $explicitTerms = [];
                    $nonExplicitTerms = [];
                    if (\count($quoteExp) > 1) {
                        foreach ($quoteExp as $key => $part) {
                            if ($key % 2 === 0) {
                                $nonExplicitTerms[] = $part;
                            } else {
                                $explicitTerms[] = $part;
                            }
                        }

                        $value = implode(' ', array_filter($nonExplicitTerms));
                    }
                    
                    // Split by spaces
                    $parts = explode(' ', $value);
                    $ands = [];
                    foreach ($parts as $part) {
                        // Check for - in front of the part indicating that the title should NOT include it
                        if (strpos($part, '-') === 0 && $part !== '-') {
                            $part = substr($part, 1);
                            $ands[] = $qb->expr()->notLike(
                                "lower(coalesce(" . $type . ".description, ''))",
                                "'%$part%'"
                            );
                        } else {
                            $ands[] = $qb->expr()->like(
                                "lower(coalesce(" . $type . ".description, ''))",
                                "'%$part%'"
                            );
                        }
                    }
                    // Add clauses for explicit terms that can't be exclusions
                    foreach ($explicitTerms as $part) {
                        $ands[] = $qb->expr()->like(
                            "lower(coalesce(" . $type . ".description, ''))",
                            "'%$part%'"
                        );
                    }

                    $where[] = $qb->expr()->and(...$ands);
                }
            }

            // Minimum episodes
            if ($key === 'episodesMin' && $value !== 0) {
                $where[] = $qb->expr()->gte('episodes', (string) $value);
            }
            // Maximum episodes
            if ($key === 'episodesMax' && $value !== 0) {
                // The db doesn't consider null = 0 so we gotta include the null values
                // If minimum episodes > 0 the null values will get filtered out again
                $where[] = $qb->expr()->or(
                    $qb->expr()->lte('episodes', (string) $value),
                    $qb->expr()->isNull('episodes'),
                );
            }
            // Minimum volumes
            if ($key === 'volumesMin' && $value !== 0) {
                $where[] = $qb->expr()->gte('volumes', (string) $value);
            }
            // Maximum volumes
            if ($key === 'volumesMax' && $value !== 0) {
                // The db doesn't consider null = 0 so we gotta include the null values
                // If minimum volumes > 0 the null values will get filtered out again
                $where[] = $qb->expr()->or(
                    $qb->expr()->lte('volumes', (string) $value),
                    $qb->expr()->isNull('volumes'),
                );
            }
            // Minimum totalRuntime
            if ($key === 'totalRuntimeMin' && $value !== 0) {
                $where[] = $qb->expr()->gte('total_duration', (string) $value);
            }
            // Maximum totalRuntime
            if ($key === 'totalRuntimeMax' && $value !== 0) {
                // The db doesn't consider null = 0 so we gotta include the null values
                // If minimum totalRuntime > 0 the null values will get filtered out again
                $where[] = $qb->expr()->or(
                    $qb->expr()->lte('total_duration', (string) $value),
                    $qb->expr()->isNull('total_duration'),
                );
            }
            // Minimum Mean Score
            if ($key === 'meanScoreMin' && $value !== 0) {
                $where[] = $qb->expr()->gte('mean_score', (string) $value);
            }
            // Maximum Mean Score
            if ($key === 'meanScoreMax' && $value !== 0) {
                // The db doesn't consider null = 0 so we gotta include the null values
                // If minimum episodes > 0 the null values will get filtered out again
                $where[] = $qb->expr()->or(
                    $qb->expr()->lte('mean_score', (string) $value),
                    $qb->expr()->isNull('mean_score'),
                );
            }
            // Minimum Average Score
            if ($key === 'avgScoreMin' && $value !== 0) {
                $where[] = $qb->expr()->gte('average_score', (string) $value);
            }
            // Maximum Average Score
            if ($key === 'avgScoreMax' && $value !== 0) {
                // The db doesn't consider null = 0 so we gotta include the null values
                // If minimum episodes > 0 the null values will get filtered out again
                $where[] = $qb->expr()->or(
                    $qb->expr()->lte('average_score', (string) $value),
                    $qb->expr()->isNull('average_score'),
                );
            }
            // Minimum Popularity
            if ($key === 'popularityMin' && $value !== 0) {
                $where[] = $qb->expr()->gte('popularity', (string) $value);
            }
            // Maximum Popularity
            if ($key === 'popularityMax' && $value !== 0) {
                // The db doesn't consider null = 0 so we gotta include the null values
                // If minimum episodes > 0 the null values will get filtered out again
                $where[] = $qb->expr()->or(
                    $qb->expr()->lte('popularity', (string) $value),
                    $qb->expr()->isNull('popularity'),
                );
            }
            // Show Adult Content
            if ($key === 'showAdult' && $value === false) {
                $where[] = $qb->expr()->eq('is_adult', '0');
            }
            // Exclude media without reviews
            if ($key === 'hasReview' && $value === true) {
                $where[] = $qb->expr()->gt('reviews', '0');
            }
            if ($key === 'format') {
                $where = array_merge($where, $this->getDirectSubClausesFor($qb, 'media.format', $value));
            }
            if ($key === 'source') {
                $where = array_merge($where, $this->getDirectSubClausesFor($qb, 'media.source', $value));
            }
            if ($key === 'country') {
                $where = array_merge($where, $this->getDirectSubClausesFor($qb, 'media.country_of_origin', $value));
            }

            if ($key === 'airStatus') {
                $where = array_merge($where, $this->getDirectSubClausesFor($qb, 'media.status', $value));
            }

            if ($key === 'bloodType') {
                $where = array_merge($where, $this->getDirectSubClausesFor($qb, 'blood_type', $value));
            }

            if ($key === 'gender') {
                $where = array_merge($where, $this->getDirectSubClausesFor($qb, 'gender', $value));
            }

            if ($key === 'season') {
                $where = array_merge($where, $this->getDirectSubClausesFor($qb, 'season', $value));
            }

            if ($key === 'year') {
                // If the filters also include a season, filter by the season_year instead
                $c = 'start_date_y';
                if (array_key_exists('season', $filters)) {
                    $c = 'season_year';
                }

                if (isset($value['or'])) {
                    $or = [];
                    foreach ($value['or'] as $v) {
                        if ($v instanceof IntRange) {
                            $or[] = $c . " BETWEEN " . $v->min . " AND " . $v->max;
                        } else {
                            $or[] = $qb->expr()->eq($c, (string) $v);
                        }
                    }
                    $where[] = $qb->expr()->or(...$or);
                }

                if (isset($value['not'])) {
                    $and = [];
                    foreach ($value['not'] as $v) {
                        if ($v instanceof IntRange) {
                            $and[] = $c . " NOT BETWEEN " . $v->min . " AND " . $v->max;
                        } else {
                            $and[] = $qb->expr()->neq($c, (string) $v);
                        }
                    }

                    $where[] = $qb->expr()->and(...$and);
                }
            }

            if ($key === 'mcCountMin' && $value !== 0) {
                $sub = $this->db->createQueryBuilder();
                $sub->select('media_id');
                $sub->from('media_characters');
                $sub->where(
                    $sub->expr()->eq('media_characters.role', "'MAIN'"),
                );
                $sub->groupBy('media_id');
                $sub->having(
                    $sub->expr()->gte('COUNT(distinct character_id)', (string) $value)
                );

                $where[] = "media.id in ($sub)";
            }

            if ($key === 'mcCountMax' && $value !== 0) {
                $sub = $this->db->createQueryBuilder();
                $sub->select('media_id');
                $sub->from('media_characters');
                $sub->where(
                    $sub->expr()->eq('media_characters.role', "'MAIN'"),
                );
                $sub->groupBy('media_id');
                $sub->having(
                    $sub->expr()->gt('COUNT(distinct character_id)', (string) $value)
                );

                $where[] = "media.id not in ($sub)";
            }

            if ($key === 'voiceActor') {
                $sub = $this->db->createQueryBuilder();
                $sub->select('media_id');
                $sub->from('media_characters');

                $where = array_merge($where, $this->getSubClausesFor($sub, 'media_characters.voice_actor_id', $value));
            }

            if ($key === 'staff') {
                $sub = $this->db->createQueryBuilder();
                $sub->select('media_id');
                $sub->from('media_staff');

                $where = array_merge($where, $this->getSubClausesFor($sub, 'media_staff.staff_id', $value));
            }

            if ($key === 'tag') {
                $where = array_merge(
                    $where,
                    $this->getJsonbSubClausesFor('media.tags', '$[*].tag', $value)
                );
            }

            if ($key === 'awcCommunityList') {
                $sub = $this->db->createQueryBuilder();
                $sub->select('media_id');
                $sub->from('awc_community_lists');

                $where = array_merge(
                    $where,
                    $this->getSubClausesFor($sub, 'awc_community_lists.community_list', $value)
                );
            }

            if ($key === 'relationToAWCCommunityList') {
                $sub = $this->db->createQueryBuilder();
                $sub->select('related_media_id');
                $sub->from('media_relations');
                $sub->innerJoin(
                    'media_relations',
                    'awc_community_lists',
                    'cl',
                    'media_relations.media_id = cl.media_id'
                );

                $where = array_merge(
                    $where,
                    $this->getSubClausesFor($sub, 'cl.community_list', $value)
                );
            }

            if ($key === 'onlyScanlated' && $value === true) {
                $sub = $this->db->createQueryBuilder();
                $sub->select('media_id');
                $sub->from('mangaupdates');
                $sub->innerJoin(
                    'mangaupdates',
                    'media_external_ids',
                    'mei',
                    'mei.service = \'MangaUpdates\' AND CAST(mei.external_id as bigint) = mangaupdates.id'
                );
                $sub->where(
                    $sub->expr()->eq('scanlation_completed', 'true')
                );
                $where[] = "media.id in ($sub)";
            }

            if ($key === 'muPublisher') {
                // Hardcoded because DBAL doesn't support cross joins
                $sub = $this->db->createQueryBuilder();
                $sub->select('media_id');
                $sub->from('mangaupdates');
                $sub->innerJoin(
                    'mangaupdates',
                    'jsonb_to_recordset(mangaupdates.publishers)',
                    'p(publisher_name text)',
                    '1=1'
                );
                $sub->innerJoin(
                    'mangaupdates',
                    'media_external_ids',
                    'mei',
                    'mei.service = \'MangaUpdates\' and cast(mei.external_id as bigint) = mangaupdates.id'
                );
                $where = array_merge($where, $this->getSubClausesFor($sub, 'publisher_name', $value));
            }

            if ($key === 'muPublication') {
                $sub = $this->db->createQueryBuilder();
                $sub->select('media_id');
                $sub->from('mangaupdates');
                $sub->innerJoin(
                    'mangaupdates',
                    'jsonb_to_recordset(mangaupdates.publications)',
                    'p(publication_name text)',
                    '1=1'
                );
                $sub->innerJoin(
                    'mangaupdates',
                    'media_external_ids',
                    'mei',
                    'mei.service = \'MangaUpdates\' and cast(mei.external_id as bigint) = mangaupdates.id'
                );
                foreach ($value as $andOrNot => $v) {
                    $value[$andOrNot] = array_map('strtolower', $v);
                }
                $where = array_merge($where, $this->getSubClausesFor($sub, 'lower(publication_name)', $value));
            }

            // User List
            if ($key === 'userList') {
                // Ignore when no username was passed
                // TODO: throw an exception telling the user to provide the username
                if ($userName === null) {
                    continue;
                }

                $customSub = $this->db->createQueryBuilder();
                $customSub->select('media_id');
                $customSub->from('user_media_list');
                $customSub->innerJoin(
                    'user_media_list',
                    'user_lists',
                    'ul',
                    'ul.id = user_media_list.list_id'
                );

                $allSub = $this->db->createQueryBuilder();
                $allSub->select('media_id');
                $allSub->from('user_media');
                $allSub->innerJoin('user_media', '"user"', '"user"', 'user_media.user_id = "user".id');
                $allSub->where(
                    $allSub->expr()->eq('lower("user".user_name)', "'" . strtolower($userName) . "'"),
                );


                // We need to split the given values since "all <status>" lists need to be handled differently
                $customLists = [];
                $allLists = [];

                // $mode = "AND", "OR" or "NOT"
                foreach ($value as $mode => $lists) {
                    foreach ($lists as $l) {
                        if (is_numeric(explode('-', $l)[0])) {
                            $customLists[$mode][] = $l;
                        }
                        if (substr($l, 0, 3) === 'all') {
                            $s = clone $allSub;
                            $exp = explode('-', $l);
                            // It's either just "all" or "all-<status>"
                            if (
                                \count($exp) === 2
                                && in_array(
                                    $exp[1],
                                    ['current','planning','completed','dropped','paused','repeating'],
                                    true
                                )
                            ) {
                                $s->andWhere($qb->expr()->eq('user_media.status', "'" . strtoupper($exp[1]) . "'"));
                            }
                            $allLists[$mode][] = $s;
                        }
                    }
                }

                $where = array_merge($where, $this->getSubClausesFor($customSub, 'ul.slug', $customLists));

                if (isset($allLists['and'])) {
                    foreach ($allLists['and'] as $s) {
                        $where[] = "media.id in ($s)";
                    }
                }
                if (isset($allLists['or'])) {
                    $t = [];
                    foreach ($allLists['or'] as $s) {
                        $t[] = "media.id in ($s)";
                    }
                    $where[] = $qb->expr()->or(...$t);
                }
                if (isset($allLists['not'])) {
                    foreach ($allLists['not'] as $s) {
                        $where[] = "media.id not in ($s)";
                    }
                }
            }
        }

        return $where;
    }

    /**
     * @return array<string, mixed[]>
     */
    public function getFilterValues(string $mediaType): array
    {
        $filterValues = [];

        if ($mediaType === 'MAPPER') {
            $mediaType = 'MANGA';
        }

        if (! in_array($mediaType, ['ANIME', 'MANGA', 'CHARACTER', 'STAFF'], true)) {
            return [];
        }

        if (array_key_exists($mediaType, $this->filterValueCache)) {
            return $this->filterValueCache[$mediaType];
        }

        $f = static function ($i) {
            return $i['value'];
        };

        if (in_array($mediaType, ['ANIME', 'MANGA'], true)) {
            $qb = $this->db->createQueryBuilder();
            $qb->from('media');
            $qb->where($qb->expr()->eq('media.media_type', "'" . $mediaType . "'"),);
            $qb->orderBy('value', 'ASC');

            $tStart = microtime(true);
            $qb->select('DISTINCT(format) AS value');
            $this->log->debug((string) $qb);
            $results = $qb->executeQuery()->fetchAllAssociative();
            $filterValues['format'] = array_filter(array_map($f, $results));
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

            $tStart = microtime(true);
            $qb->select('DISTINCT(source) AS value');
            $this->log->debug((string) $qb);
            $results = $qb->executeQuery()->fetchAllAssociative();
            $filterValues['source'] = array_filter(array_map($f, $results));
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

            $tStart = microtime(true);
            $qb->select('DISTINCT(country_of_origin) AS value');
            $this->log->debug((string) $qb);
            $results = $qb->executeQuery()->fetchAllAssociative();
            $filterValues['country_of_origin'] = array_filter(array_map($f, $results));
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

            $tStart = microtime(true);
            $qb->select('DISTINCT(status) AS value');
            $this->log->debug((string) $qb);
            $results = $qb->executeQuery()->fetchAllAssociative();
            $filterValues['status'] = array_filter(array_map($f, $results));
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

            $filterValues['season'] = ['WINTER', 'SPRING', 'SUMMER', 'FALL'];

            $tStart = microtime(true);
            $qb->select('DISTINCT(start_date_y) AS value');
            $this->log->debug((string) $qb);
            $results = $qb->executeQuery()->fetchAllAssociative();
            $filterValues['season_year'] = array_reverse(array_filter(array_map($f, $results)));
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

            if ($mediaType === 'ANIME') {
                $tStart = microtime(true);
                $qb->select('DISTINCT(media.episodes * media.duration) AS value');
                $this->log->debug((string) $qb);
                $results = $qb->executeQuery()->fetchAllAssociative();
                $filterValues['total_runtime'] = array_values(array_filter(array_map($f, $results)));
                $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');
            }

            $tStart = microtime(true);
            $qb->select('DISTINCT(episodes) AS value');
            $this->log->debug((string) $qb);
            $results = $qb->executeQuery()->fetchAllAssociative();
            $filterValues['episodes'] = array_values(array_filter(array_map($f, $results)));
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

            if ($mediaType === 'MANGA') {
                $tStart = microtime(true);
                $qb->select('DISTINCT(volumes) AS value');
                $this->log->debug((string) $qb);
                $results = $qb->executeQuery()->fetchAllAssociative();
                $filterValues['volumes'] = array_values(array_filter(array_map($f, $results)));
                $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');
            }

            $tStart = microtime(true);
            $qb->select('MAX(popularity) AS value');
            $this->log->debug((string) $qb);
            $result = $qb->executeQuery()->fetchAssociative();
            $filterValues['popularity'] = $result['value'];
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

            // Get a list of amounts of main characters
            $tStart = microtime(true);
            $sub = $this->db->createQueryBuilder();
            $sub->select('media_id', 'COUNT(distinct character_id) as value');
            $sub->from('media_characters');
            $sub->where(
                $sub->expr()->eq('media_characters.role', "'MAIN'")
            );
            $sub->groupBy('media_id');
            $sel = $this->db->createQueryBuilder();
            $sel->select('DISTINCT value');
            $sel->from("($sub)", 'mccount');
            $sel->orderBy('value', 'asc');
            $this->log->debug((string) $sel);
            $results = $sel->executeQuery()->fetchAllAssociative();
            $filterValues['mcCount'] = array_values(array_filter(array_map($f, $results)));
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

            $tStart = microtime(true);
            $query = 'SELECT DISTINCT jsonb_array_elements_text(genres) AS value FROM media ORDER BY value ASC';
            $this->log->debug($query);
            $results = $this->db->executeQuery($query)->fetchAllAssociative();
            $filterValues['genres'] = array_filter(array_map($f, $results));
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

            $tStart = microtime(true);
            $clone = $this->db->createQueryBuilder();
            $clone->select('tag_name AS value, category');
            $clone->from('media_tagcollection');
            $clone->orderBy('category', 'ASC');
            $this->log->debug((string) $clone);
            $results = $clone->executeQuery()->fetchAllAssociative();
            $grouped = [];
            foreach ($results as $row) {
                $grouped[$row['category']][] = $row['value'];
            }
            $filterValues['tags'] = $grouped;
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

            $tStart = microtime(true);
            $query = 'SELECT DISTINCT jsonb_array_elements_text(media.external_links) AS value'
                . ' FROM media ORDER BY value ASC';
            $this->log->debug($query);
            $results = $this->db->executeQuery($query)->fetchAllAssociative();
            $filterValues['external_links'] = array_filter(array_map($f, $results));
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

            $tStart = microtime(true);
            $clone = $this->db->createQueryBuilder();
            $clone->select('DISTINCT(community_list) AS value');
            $clone->from('awc_community_lists');
            $this->log->debug((string) $clone);
            $results = $clone->executeQuery()->fetchAllAssociative();
            $filterValues['awc_community_lists'] = array_filter(array_map($f, $results));
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');
        } elseif (in_array($mediaType, ['CHARACTER', 'STAFF'], true)) {
            $table = match ($mediaType) {
                'CHARACTER' => 'characters',
                'STAFF' => 'staff',
            };

            $tStart = microtime(true);
            $clone = $this->db->createQueryBuilder();
            $clone->select('DISTINCT(blood_type) AS value');
            $clone->from($table);
            $this->log->debug((string) $clone);
            $results = $clone->executeQuery()->fetchAllAssociative();
            $filterValues['blood_type'] = array_values(array_filter(array_map($f, $results)));
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

            $tStart = microtime(true);
            $clone = $this->db->createQueryBuilder();
            $clone->select('DISTINCT(gender) AS value');
            $clone->from($table);
            $this->log->debug((string) $clone);
            $results = $clone->executeQuery()->fetchAllAssociative();
            $filterValues['gender'] = array_filter(array_map($f, $results));
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');

            if ($mediaType === 'STAFF') {
                $tStart = microtime(true);
                $query = 'SELECT DISTINCT jsonb_array_elements_text(primary_occupations) AS value'
                    . ' FROM staff ORDER BY value ASC';
                $this->log->debug($query);
                $results = $this->db->executeQuery($query)->fetchAllAssociative();
                $filterValues['primary_occupations'] = array_filter(array_map($f, $results));
            $this->log->debug(((microtime(true) - $tStart) * 1000) . 'ms');
            }
        }

        $this->filterValueCache[$mediaType] = $filterValues;

        return $filterValues;
    }

    /** @return array<array<string, mixed>> | array<string, array<int, array<string, mixed>>> */
    public function getUserLists(
        string $userName,
        string $mediaType,
        bool $withTimeout,
        ?string $authToken,
    ): array {
        $this->log->debug('Retrieving current MediaListCollection from AniList');
        $tStart = microtime(true);
        $response = AniListClient::request(
            self::USER_LIST_QUERY,
            [
                'userName' => $userName,
                'mediaType' => $mediaType,
            ],
            $authToken,
            null,
            $withTimeout
        );
        $this->log->debug('Request took:' . ((microtime(true) - $tStart) * 1000) . 'ms');
        $errors = [];
        $warnings = [];

        if (isset($response['data']['MediaListCollection'])) {
            $this->dbService->importUser($response['data']['MediaListCollection'], $mediaType);
        } else {
            $warnings[] = [
                'source' => 'AniList',
                'message' => 'AniList API didn\'t return any data while trying to update lists',
            ];
        }

        $lists = $this->dbService->getUserLists($userName, $mediaType);

        $output = ['data' => $lists];

        if (isset($response['warnings'])) {
            $output['warnings'] = $response['warnings'];
        }

        if (isset($response['errors'])) {
            $errors = $response['errors'];
        }

        if (\count($lists) === 0) {
            $errors[] = [
                'source' => 'AniTools',
                'message' => 'Couldn\'t find any lists for specified user',
            ];
        }

        if (\count($errors) > 0) {
            $output['errors'] = $errors;
        }

        return $output;
    }

    /**
     * @param list<array<string, mixed>> $columns
     * @return list<string>
     */
    private function mapColumns(MediaType $mediaType, array $columns, ?string $userName): array
    {
        $mapped = [];
        // We always need the ID (and the cover image because it isn't requested through a column)
        if ($mediaType === MediaType::ANIME || $mediaType === MediaType::MANGA) {
            $mapped = [
                'media.id AS id',
                'media.cover_image AS "coverImage"',
            ];
        }
        if ($mediaType === MediaType::CHARACTER) {
            $mapped = [
                'characters.id AS id',
                'characters.image AS "coverImage"',
            ];
        }
        if ($mediaType === MediaType::STAFF) {
            $mapped = [
                'staff.id AS id',
                'staff.image AS "coverImage"',
            ];
        }

        foreach ($columns as $c) {
            // Ignore unknown columns
            if (! array_key_exists($c['name'], self::COLUMN_MAP)) {
                continue;
            }

            // Return an empty string if the column isn't visible. DataTables freaks out if a column isn't in the
            // response. Also return an empty string if no username was given for user_media columns
            // The id column can't be empty because we group the results by the id in the query
            // The started and completed columns can't be empty because we need the data for the "Code" button
            if (
                ! in_array($c['name'], ['id', 'started', 'completed'], true) && (
                    $c['visible'] === false || (
                        strpos(self::COLUMN_MAP[$c['name']], 'user_media') !== false &&
                        $userName === null
                    )
                )
            ) {
                $mapped[] = 'null AS ' . $this->db->quoteIdentifier($c['name']);
            } else {
                if ($c['name'] === 'id') {
                    $mapped[] = match ($mediaType) {
                        MediaType::ANIME => 'media.id',
                        MediaType::MANGA => 'media.id',
                        MediaType::CHARACTER => 'characters.id',
                        MediaType::STAFF => 'staff.id',
                    } . ' AS ' . $this->db->quoteIdentifier($c['name']);
                } else {
                    $mapped[] = self::COLUMN_MAP[$c['name']] . ' AS ' . $this->db->quoteIdentifier($c['name']);
                }
            }
        }
        $mapped = array_unique($mapped);

        return $mapped;
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<array<string, mixed>> $columns
     * @param list<array<string, string>> $sortCriteria
     * @return array<string, mixed>
     */
    public function searchForCharacter(
        array $filters,
        array $columns,
        int $start,
        int $length,
        array $sortCriteria,
        ?string $userName,
    ) {
        $timings = [];
        // If the following columns are being queried, they need to get returned as subarrays
        $requireSubData = [
            'appearances',
            'nameAlternatives',
            'nameAlternativesSpoiler',
        ];
        $subDataNeeded = [];
        foreach ($columns as $c) {
            if (in_array($c['name'], $requireSubData, true) && $c['visible'] === true) {
                $subDataNeeded[] = $c['name'];
            }
        }

        $tStart = microtime(true);
        $sel = $this->db->createQueryBuilder();

        $tCols = [
            'COUNT(characters.id) AS count',
        ];
        $sel->select(...$tCols);
        $sel->from('characters');
        $totalSel = clone $sel;

        $totals = $this->getTotal(MediaType::CHARACTER);
        $totalAmount = $totals['count'];

        // Now figure out the amount of filtered entries
        $whereClauses = $this->getWhereClauses('characters', $filters, $sel, $userName);

        if (\count($whereClauses) > 0) {
            $totalSel->where(...$whereClauses);
        }
        $this->log->debug((string) $totalSel, ['username' => '(' . ($userName ?? 'Anonymous') . ') ']);
        $filteredTotals = $totalSel->executeQuery()->fetchAssociative();

        $timings[] = 'db-total;dur=' . ((microtime(true) - $tStart) * 1000);

        $tStart = microtime(true);
        $colMap = $this->mapColumns(MediaType::CHARACTER, $columns, $userName);
        $sel->select(...$colMap);
        if (\count($whereClauses) > 0) {
            $sel->where(...$whereClauses);
        }

        foreach ($subDataNeeded as $c) {
            if ($c === 'appearances') {
                $sub = $this->db->createQueryBuilder();
                $sub->select('media_characters.character_id', 'COUNT(DISTINCT media_characters.media_id) AS amount');
                $sub->from('media_characters');
                $sub->groupBy('media_characters.character_id');
                $sel->leftJoin(
                    'characters',
                    '(' . $sub . ')',
                    'appearances',
                    'appearances.character_id = characters.id'
                );
            }
        }

        $sel->setMaxResults($length);
        $sel->setFirstResult($start);

        // Handle sorting instructions provided by the user
        if (\count($sortCriteria) > 0) {
            foreach ($sortCriteria as $criterium) {
                $mapped = self::ORDER_MAP[$criterium['column']]
                    ?? self::COLUMN_MAP[$criterium['column']]
                    ?? $criterium['column'];

                // TODO: maybe split into manga and anime?
                // We need extra steps if we want to sort by the amount of appearances
                if ($mapped === 'appearances' && $userName !== null) {
                    $mapped = 'appearances.amount';
                }

                if (is_string($mapped)) {
                    $mapped = [$mapped];
                }

                    foreach ($mapped as $m) {
                        $sel->addOrderBy($m . ' ' . $criterium['dir'] . ' NULLS LAST');
                }
            }
        }
        // When sorting by columns that may contain the same values (e.g. Format), the database may return
        // duplicated data on consecutive pages, so we always add an order by media.id to the SQL to prevent that
        $sel->addOrderBy('characters.id ASC');

        // Get the data for the requested page
        $this->log->debug((string) $sel, ['username' => '(' . ($userName ?? 'Anonymous') . ') ']);
        $results = $sel->executeQuery()->fetchAllAssociativeIndexed();
        $timings[] = 'db-page-main;dur=' . ((microtime(true) - $tStart) * 1000);
        $ids = array_keys($results);
        $rowNum = $start;
        foreach ($ids as $id) {
            $results[$id]['id'] = $id;
            $results[$id]['rowNum'] = ++$rowNum;
        }

        if (\count($subDataNeeded) > 0 && \count($ids) > 0) {
            $tStart = microtime(true);
            foreach ($subDataNeeded as $key) {
                foreach ($results as $id => $r) {
                    if (is_string($r[$key])) {
                        $results[$id][$key] = json_decode($r[$key], true);
                    } else {
                        $results[$id][$key] = [];
                    }
                }
            }
            $timings[] = 'db-page-sub;dur=' . ((microtime(true) - $tStart) * 1000);
        }

        // Spit out only the columns the user specified
        return [
            'total' => $totalAmount,
            'filtered' => $filteredTotals['count'],
            'data' => array_values($results),
            'timings' => $timings,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<array<string, mixed>> $columns
     * @param list<array<string, string>> $sortCriteria
     * @return array<string, mixed>
     */
    public function searchForStaff(
        array $filters,
        array $columns,
        int $start,
        int $length,
        array $sortCriteria,
        ?string $userName,
    ) {
        $timings = [];
        // If the following columns are being queried, they need to get returned as subarrays
        $requireSubData = [
            'primaryOccupations',
            'appearances',
            'nameAlternatives',
        ];
        $subDataNeeded = [];
        foreach ($columns as $c) {
            if (in_array($c['name'], $requireSubData, true) && $c['visible'] === true) {
                $subDataNeeded[] = $c['name'];
            }
        }

        $tStart = microtime(true);
        $sel = $this->db->createQueryBuilder();

        $tCols = [
            'COUNT(staff.id) AS count',
        ];
        $sel->select(...$tCols);
        $sel->from('staff');
        $totalSel = clone $sel;

        $totals = $this->getTotal(MediaType::STAFF);
        $totalAmount = $totals['count'];

        // Now figure out the amount of filtered entries
        $whereClauses = $this->getWhereClauses('staff', $filters, $sel, $userName);

        if (\count($whereClauses) > 0) {
            $totalSel->where(...$whereClauses);
        }
        $this->log->debug((string) $totalSel, ['username' => '(' . ($userName ?? 'Anonymous') . ') ']);
        $filteredTotals = $totalSel->executeQuery()->fetchAssociative();

        $timings[] = 'db-total;dur=' . ((microtime(true) - $tStart) * 1000);

        $tStart = microtime(true);
        $colMap = $this->mapColumns(MediaType::STAFF, $columns, $userName);
        $sel->select(...$colMap);
        if (\count($whereClauses) > 0) {
            $sel->where(...$whereClauses);
        }

        foreach ($subDataNeeded as $c) {
            if ($c === 'appearances') {
                $sub = $this->db->createQueryBuilder();
                $sub->select('media_staff.staff_id', 'COUNT(DISTINCT media_staff.media_id) AS amount');
                $sub->from('media_staff');
                $sub->groupBy('media_staff.staff_id');
                $sel->leftJoin('staff', '(' . $sub . ')', 'appearances', 'appearances.staff_id = staff.id');
            }
        }

        $sel->setMaxResults($length);
        $sel->setFirstResult($start);

        // Handle sorting instructions provided by the user
        if (\count($sortCriteria) > 0) {
            foreach ($sortCriteria as $criterium) {
                $mapped = self::ORDER_MAP[$criterium['column']]
                    ?? self::COLUMN_MAP[$criterium['column']]
                    ?? $criterium['column'];

                // TODO: maybe split into manga and anime?
                // We need extra steps if we want to sort by the amount of appearances
                if ($mapped === 'appearances') {
                    $mapped = 'appearances.amount';
                }

                if (is_string($mapped)) {
                    $mapped = [$mapped];
                }

                    foreach ($mapped as $m) {
                        $sel->addOrderBy($m . ' ' . $criterium['dir'] . ' NULLS LAST');
                }
            }
        }
        // When sorting by columns that may contain the same values (e.g. Format), the database may return
        // duplicated data on consecutive pages, so we always add an order by media.id to the SQL to prevent that
        $sel->addOrderBy('staff.id ASC');

        // Get the data for the requested page
        $this->log->debug((string) $sel, ['username' => '(' . ($userName ?? 'Anonymous') . ') ']);
        $results = $sel->executeQuery()->fetchAllAssociativeIndexed();
        $timings[] = 'db-page-main;dur=' . ((microtime(true) - $tStart) * 1000);
        $ids = array_keys($results);
        $rowNum = $start;
        foreach ($ids as $id) {
            $results[$id]['id'] = $id;
            $results[$id]['rowNum'] = ++$rowNum;
        }

        if (\count($subDataNeeded) > 0 && \count($ids) > 0) {
            $tStart = microtime(true);
            foreach ($subDataNeeded as $key) {
                foreach ($results as $id => $r) {
                    if (is_string($r[$key])) {
                        $results[$id][$key] = json_decode($r[$key], true);
                    } else {
                        $results[$id][$key] = [];
                    }
                }
            }
            $timings[] = 'db-page-sub;dur=' . ((microtime(true) - $tStart) * 1000);
        }

        // Spit out only the columns the user specified
        return [
            'total' => $totalAmount,
            'filtered' => $filteredTotals['count'],
            'data' => array_values($results),
            'timings' => $timings,
        ];
    }

    /**
     * @param array<string, mixed> $filters
     * @param list<array<string, mixed>> $columns
     * @param list<array<string, string>> $sortCriteria
     * @return array<string, mixed>
     */
    public function searchForMedia(
        MediaType $mediaType,
        array $filters,
        array $columns,
        int $start,
        int $length,
        array $sortCriteria,
        ?string $userName,
        ?User $authedUser,
    ): array {
        $timings = [];
        // If the following columns are being queried, they need to get returned as subarrays
        $requireSubData = [
            'genres',
            'tags',
            'studios',
            'producers',
            'externalLinks',
            'references',
        ];
        $subDataNeeded = [];
        foreach ($columns as $c) {
            if (in_array($c['name'], $requireSubData, true) && $c['visible'] === true) {
                $subDataNeeded[] = $c['name'];
            }
        }

        $tStart = microtime(true);
        $sel = $this->db->createQueryBuilder();

        $tCols = [
            'COUNT(media.id) AS count',
            'SUM(media.episodes) as episodes',
            'SUM(media.episodes * media.duration) as runtime',
            'SUM(media.volumes) as volumes',
        ];
        $sel->select(...$tCols);
        $sel->from('media');

        $totalSel = clone $sel;

        if ($userName !== null) {
            $sub = $this->db->createQueryBuilder();
            $sub->select('*');
            $sub->from('user_media');
            $sub->innerJoin('user_media', '"user"', '"user"', 'user_media.user_id = "user".id');
            $sWhere = [
                $sub->expr()->eq('lower("user".user_name)', "'" . strtolower($userName) . "'")
            ];
            // Make sure that users can only see their own private stuff
            if ($authedUser === null || $authedUser->userName !== $userName) {
                $sWhere[] = $sub->expr()->eq('is_private', 'false');
            }
            $sub->where(...$sWhere);
            $sel->leftJoin('media', '(' . $sub . ')', 'user_media', 'media.id = user_media.media_id');
        }

        $whereClauses = [
            $sel->expr()->eq('media.media_type', "'" . $mediaType->name . "'"),
        ];

        // We need to make some adjustments for determining the "total entries" in case user lists are involved
        // However since the filtering theoretically allows you to create very specific conditions to the point where
        // you can't determine what "total entries" are even considered we'll only look on simple cases
        if (isset($filters['and']['userList']['and']) && $userName !== null) {
            $totalSel->innerJoin('media', '(' . $sub . ')', 'user_media', 'media.id = user_media.media_id');
            // Add a column to get the amount of entries the user has completed
            $tCols[] = 'SUM(CASE WHEN user_media.status = \'COMPLETED\' THEN 1 ELSE 0 END) as count_completed';
            $totalSel->select(...$tCols);

            $totalWhere = [
                'userList' => $filters['and']['userList'],
            ];

            $totalWhere = $this->getWhereClauses('media', $totalWhere, $totalSel, $userName);
            $totalSel->where(...$totalWhere);

            $this->log->debug((string) $totalSel, ['username' => '(' . $userName . ') ']);
            $result = $totalSel->executeQuery()->fetchAssociative();
            $totalAmount = $result['count'];
            $totalEpisodes = $result['episodes'];
            $totalVolumes = $result['volumes'];
            $totalRuntime = $result['runtime'];
            $totalCompleted = $result['count_completed'];
        } else {
            $totals = $this->getTotal($mediaType);
            $totalAmount = $totals['count'];
            $totalEpisodes = $totals['episodes'];
            $totalVolumes = $totals['volumes'];
            $totalRuntime = $totals['runtime'];
            $totalCompleted = 0;
        }

        // Now figure out the amount of filtered entries
        $whereClauses = array_merge($whereClauses, $this->getWhereClauses('media', $filters, $sel, $userName));

            $totalSel->where(...$whereClauses);
        $this->log->debug((string) $totalSel, ['username' => '(' . ($userName ?? 'Anonymous') . ') ']);
        $filteredTotals = $totalSel->executeQuery()->fetchAssociative();

        $timings[] = 'db-total;dur=' . ((microtime(true) - $tStart) * 1000);

        $tStart = microtime(true);
        $colMap = $this->mapColumns($mediaType, $columns, $userName);
        $sel->select(...$colMap);
        $sel->where(...$whereClauses);

        foreach ($subDataNeeded as $c) {
            if ($c === 'references' && $userName !== null) {
                $sub = $this->db->createQueryBuilder();
                $sub->select('media_id', 'JSON_AGG(user_lists.name) as references');
                $sub->from('user_media_list', 'umlsub');
                $sub->innerJoin('umlsub', 'user_lists', 'user_lists', 'umlsub.list_id = user_lists.id');
                $sub->innerJoin('umlsub', '"user"', '"user"', '"user".id = umlsub.user_id');
                $sub->where(
                    $sel->expr()->eq('user_lists.is_custom_list', 'true'),
                    $sel->expr()->eq('lower("user".user_name)', "'" . strtolower($userName) . "'"),
                );
                $sub->groupBy('media_id');
                $sel->leftJoin('media', "($sub)", 'uml', 'uml.media_id = media.id');
            }
        }

        $sel->setMaxResults($length);
        $sel->setFirstResult($start);

        // Handle sorting instructions provided by the user
        if (\count($sortCriteria) > 0) {
            foreach ($sortCriteria as $criterium) {
                $mapped = self::ORDER_MAP[$criterium['column']]
                    ?? self::COLUMN_MAP[$criterium['column']]
                    ?? $criterium['column'];

                // We need extra steps if we want to sort by the amount of references
                if ($mapped === 'references' && $userName !== null) {
                    $sub = $this->db->createQueryBuilder();
                    $sub->select('user_media_list.media_id', 'COUNT(user_lists.name) AS amount');
                    $sub->from('user_media_list');
                    $sub->innerJoin(
                        'user_media_list',
                        'user_lists',
                        'user_lists',
                        'user_media_list.list_id = user_lists.id'
                    );
                    $sub->innerJoin('user_media_list', '"user"', '"user"', '"user".id = user_media_list.user_id');
                    $sub->where(
                        $sub->expr()->eq('user_lists.is_custom_list', 'true'),
                        $sel->expr()->eq('lower("user".user_name)', "'" . strtolower($userName) . "'"),
                    );
                    $sub->groupBy('user_media_list.media_id');
                    $sel->leftJoin('media', '(' . $sub . ')', 'ref_count', 'ref_count.media_id = media.id');
                    $mapped = 'ref_count.amount';
                }

                if (is_string($mapped)) {
                    $mapped = [$mapped];
                }

                    foreach ($mapped as $m) {
                        $sel->addOrderBy($m . ' ' . $criterium['dir'] . ' NULLS LAST');
                }
            }
        }
        // When sorting by columns that may contain the same values (e.g. Format), the database may return
        // duplicated data on consecutive pages, so we always add an order by media.id to the SQL to prevent that
        $sel->addOrderBy('media.id ASC');

        // Get the data for the requested page
        $this->log->debug((string) $sel, ['username' => '(' . ($userName ?? 'Anonymous') . ') ']);
        $results = $sel->executeQuery()->fetchAllAssociativeIndexed();
        $timings[] = 'db-page-main;dur=' . ((microtime(true) - $tStart) * 1000);
        $mediaIds = array_keys($results);
        $rowNum = $start;
        foreach ($mediaIds as $id) {
            $results[$id]['id'] = $id;
            $results[$id]['rowNum'] = ++$rowNum;
        }

        if (\count($subDataNeeded) > 0 && \count($mediaIds) > 0) {
            $tStart = microtime(true);
            foreach ($subDataNeeded as $key) {
                foreach ($results as $id => $r) {
                    if (is_string($r[$key])) {
                        $results[$id][$key] = json_decode($r[$key], true);
                    } else {
                        $results[$id][$key] = [];
                    }
                }
            }
            $timings[] = 'db-page-sub;dur=' . ((microtime(true) - $tStart) * 1000);
        }

        // Spit out only the columns the user specified
        return [
            'total' => $totalAmount,
            'filtered' => $filteredTotals['count'],
            'filtered_episodes' => $filteredTotals['episodes'],
            'filtered_runtime' => $filteredTotals['runtime'],
            'filtered_volumes' => $filteredTotals['volumes'],
            'total_episodes' => $totalEpisodes,
            'total_volumes' => $totalVolumes,
            'total_runtime' => $totalRuntime,
            'total_completed' => $totalCompleted,
            'data' => array_values($results),
            'timings' => $timings,
        ];
    }

    /** @return list<array<string, mixed>> */
    public function searchStaff(string $search): array
    {
        $qb = $this->db->createQueryBuilder();
        $qb->select(
            'staff.id',
            'name_first',
            'name_middle',
            'name_last',
        );
        $qb->from('staff');
        $qb->addOrderBy('name_last', 'ASC');
        $qb->addOrderBy('name_first', 'ASC');
        $exp = explode(' ', $search);
        $clauses = [];
        foreach ($exp as $part) {
            $clauses[] = is_numeric($part)
                ? $qb->expr()->eq('staff.id', $part)
                : "lower("
                    . "coalesce(name_first, '') || ' ' || coalesce(name_middle, '') || ' ' || coalesce(name_last, '')"
                    . ") LIKE '%" . strtolower($part) . "%'";
        }
        $qb->where(...$clauses);
        $qb->setMaxResults(10);
        $this->log->debug((string) $qb);
        $result = $qb->executeQuery()->fetchAllAssociative();

        $output = [];
        foreach ($result as $row) {
            $label = $row['name_first'];
            if ($row['name_middle'] !== null) {
                $label .= ' ' . $row['name_middle'];
            }
            if ($row['name_last'] !== null) {
                $label .= ' ' . $row['name_last'];
            }
            $output[] = ['value' => (string) $row['id'], 'text' => $label];
        }

        return $output;
    }

    /** @return list<array<string, mixed>> */
    public function searchForFilter(string $filter, string $search): array
    {
        $exp = explode(' ', $search);

        $qb = $this->db->createQueryBuilder();
        $qb->distinct();
        $qb->orderBy('value', 'asc');

        switch (true) {
            case $filter === 'studio':
            case $filter === 'producer':
                $qb->select('tmp AS value');
                $whereExp = "lower(tmp) LIKE '%%%s%%'";
                $sub = $this->db->createQueryBuilder();
                $sub->select('jsonb_array_elements_text(media.' . $filter . 's) AS tmp');
                $sub->from('media');
                $qb->from('(' . $sub . ')');
                break;
            case $filter === 'muPublication':
                $qb->select("publication_name AS value");
                $qb->from('mangaupdates, jsonb_to_recordset(mangaupdates.publications) as p(publication_name text)');
                $whereExp = "lower(publication_name) LIKE '%%%s%%'";
                break;
            case $filter === 'muPublisher':
                $qb->select("publisher_name AS value");
                $qb->from('mangaupdates, jsonb_to_recordset(mangaupdates.publishers) as p(publisher_name text)');
                $whereExp = "lower(publisher_name) LIKE '%%%s%%'";
                break;
            default:
                throw new InvalidArgumentException("Filter '$filter' isn't supported through this method.");
        }

        $clauses = [];
        foreach ($exp as $part) {
            $clauses[] = sprintf($whereExp, strtolower($part));
        }
        $qb->where(...$clauses);
        $qb->setMaxResults(10);

        $this->log->debug((string) $qb);
        $result = $qb->executeQuery()->fetchAllAssociative();

        $output = [];
        foreach ($result as $row) {
            $output[] = ['value' => (string) $row['value'], 'text' => $row['value']];
        }

        return $output;
    }
}
