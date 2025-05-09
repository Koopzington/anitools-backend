<?php

declare(strict_types=1);

namespace AniTools;

use AniTools\Scraper\Animeshon;
use AniTools\Scraper\MangaDex;
use AniTools\Scraper\MangaUpdates;
use Doctrine\DBAL\Connection;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @phpstan-type ALMedia array{
 *  id: int,
 *  idMal: int | null,
 *  title: array<'romaji' | 'english' | 'native', string>,
 *  description: string,
 *  season?: string,
 *  seasonYear?: int,
 *  format: string,
 *  countryOfOrigin: string,
 *  tags: array<int, array{
 *    name: string,
 *    rank: int,
 *    isMediaSpoiler: bool,
 *    isGeneralSpoiler: bool
 *  }>,
 *  genres: array<int, string>,
 *  episodes?: int,
 *  duration?: int,
 *  chapters?: int,
 *  volumes?: int,
 *  source: string,
 *  averageScore: int,
 *  meanScore:int,
 *  popularity: int,
 *  favourites: int,
 *  status: string,
 *  isAdult: bool,
 *  isLicensed: bool,
 *  studios?: array{
 *    edges: array<int, array{
 *      node: array{
 *        name: string
 *      },
 *      isMain: bool
 *    }>
 *  },
 *  stats: array{
 *    statusDistribution: array<int, array{
 *      status: string,
 *      amount: int
 *    }>
 *  },
 *  reviews: array{
 *    pageInfo: array{
 *      total: int
 *    }
 *  },
 *  startDate: array{
 *    year: int | null,
 *    month: int | null,
 *    day: int | null
 *  },
 *  endDate: array{
 *    year: int | null,
 *    month: int | null,
 *    day: int | null
 *  },
 *  coverImage: array{
 *    large : string
 *  },
 *  externalLinks: array<int, array{
 *    site: string
 *  }>,
 *  synonyms: array<int, string>
 * }
 */
final class Importer
{
    public const VALID_DATATYPES = [
        'anilist',
        'awc',
        'awc-leaderboard',
        'media-tag-collection',
        'animeshon',
        'mangaupdates',
        'mangadex',
    ];

    private const IMPORT_DIR = 'data/import/';
    private const CHUNK_SIZE = 5000;

    // Arrays which will get filled while importing the media data and batch-inserted afterwards
    /** @var array<int, array<string, mixed>> */
    private array $externalIds = [];

    private Logger $log;

    public function __construct(
        private Connection $db,
        private OutputInterface $output
    ) {

        $logger = new Logger('API');
        $formatter = new LineFormatter(
            "%datetime% %channel%.%level_name%: %message%\n",
            'Y-m-d H:i:s',
            true,
            true
        );
        $handler = new StreamHandler('./data/logs/import.log', Level::Debug);
        $handler->setFormatter($formatter);
        $logger->pushHandler($handler);

        $this->log = $logger;
    }

    public function import(string $source): int
    {
        $this->externalIds = [];

        return match ($source) {
            'anilist' => $this->importAniListData(),
            'awc' => $this->importAWCData(),
            'awc-leaderboard' => $this->importAWCLeaderboard(),
            'media-tag-collection' => $this->importMediaTagCollection(),
            'animeshon' => $this->importAnimeShonCrossRefs(),
            'mangaupdates' => $this->importMangaUpdatesSeries(),
            'mangadex' => $this->importMangaDexMappings(),
            default => Command::FAILURE,
        };
    }

    private function importAWCData(): int
    {
        // Clear tables before import
        $this->db->beginTransaction();
        $this->db->executeQuery('DELETE FROM awc_community_lists;');
        $this->db->executeQuery('DELETE FROM awc_gamblers_bot_picks');
        $this->db->commit();

        $this->output->writeln('Importing AWC community lists');
        $this->importCommunityLists();

        $this->output->writeln('Importing Bot picks for Gambler Challenges');
        $this->importGamblersBotPicks();

        $this->output->writeln('Import finished');

        return Command::SUCCESS;
    }

    private function importAniListData(): int
    {
        $this->clearTables();
        $this->importMediaTagCollection();
        $this->importMediaData('anime');
        $this->importMediaData('manga');
        $this->output->writeln('Importing staff');
        $this->importStaff();
        $this->output->writeln('Importing characters');
        $this->importCharacters();
        $this->output->writeln('Importing MAL ids');
        $this->importSubData($this->externalIds, 'media_external_ids');
        $this->output->writeln('Importing anime relations');
        $this->importRelations('anime');
        $this->output->writeln('Importing manga relations');
        $this->importRelations('manga');
        $this->output->writeln('Import finished');

        return Command::SUCCESS;
    }

    private function importMediaData(string $mediaType): void
    {
        $this->output->writeln('Importing ' . $mediaType . ' data');
        $file = self::IMPORT_DIR . 'data-' . $mediaType . '.json';

        if (! file_exists($file)) {
            $this->output->writeln("<error>File: '$file' doesn't exist.</error>");
        }

        $data = json_decode(file_get_contents($file), true);
        if (\count($data) === 0) {
        }

        // Delete media from table that got deleted on AL's side
        $allMediaIds = [];
        foreach ($data as $row) {
            $allMediaIds[] = $row['id'];
        }
        if (\count($allMediaIds) > 0) {
            $this->db->executeQuery(
                "DELETE FROM media WHERE media.media_type = '" . strtoupper($mediaType)
                . "' AND media.id NOT IN (" . implode(',', $allMediaIds) . ")"
            );
        }
        unset($allMediaIds);

        $progressbar = new ProgressBar($this->output, \count($data));
        $chunks = array_chunk($data, self::CHUNK_SIZE);
        foreach ($chunks as $chunk) {
            $this->db->beginTransaction();
            $values = [];
            /** @var ALMedia $media */
            foreach ($chunk as $media) {
                $statsCurrent = 0;
                $statsPlanning = 0;
                $statsCompleted = 0;
                $statsDropped = 0;
                $statsPaused = 0;

                    foreach ($media['stats']['statusDistribution'] as $s) {
                    if ($s['status'] === 'CURRENT') {
                                $statsCurrent = $s['amount'];
                    } elseif ($s['status'] === 'PLANNING') {
                                $statsPlanning = $s['amount'];
                    } elseif ($s['status'] === 'COMPLETED') {
                                $statsCompleted = $s['amount'];
                    } elseif ($s['status'] === 'DROPPED') {
                                $statsDropped = $s['amount'];
                    } elseif ($s['status'] === 'PAUSED') {
                                $statsPaused = $s['amount'];
                    }
                }

                // AniList didn't always have validation for data submissions and thus we need to clean up some things
                // to avoid anything that could crash queries (like invalid dates)
                // As of 2024-01-19 the start dates all seem to be valid but there are some older entries
                // with finish dates which have to get checked until they've been fixed by AL
                if ($media['endDate']['month'] > 12) {
                    $media['endDate']['month'] = null;
                }
                if ($media['endDate']['day'] > 31) {
                    $media['endDate']['day'] = null;
                }
                if (
                    $media['endDate']['year'] !== null &&
                    $media['endDate']['month'] !== null &&
                    $media['endDate']['day'] !== null
                ) {
                    $isEndDateValid = checkdate(
                        $media['endDate']['month'],
                        $media['endDate']['day'],
                        $media['endDate']['year'],
                    );
                    // Set the day to null on invalid dates like XXXX-02-30 and XXXX-04-31
                    if (! $isEndDateValid) {
                        $media['endDate']['day'] = null;
                    }
                }

                // Insert the media
                $v = [
                    'id' => $media['id'],
                    'media_type' => strtoupper($mediaType),
                    'title_native' => $media['title']['native'],
                    'title_romaji' => $media['title']['romaji'],
                    'title_english' => $media['title']['english'],
                    'description' => $media['description'],
                    'season' => $media['season'] ?? null,
                    'season_year' => $media['seasonYear'] ?? null,
                    'format' => $media['format'],
                    'country_of_origin' => $media['countryOfOrigin'],
                    'episodes' => $mediaType === 'anime' ? $media['episodes'] : $media['chapters'],
                    'duration' => $media['duration'] ?? null,
                    'source' => $media['source'],
                    'average_score' => $media['averageScore'],
                    'mean_score' => $media['meanScore'],
                    'favourites' => $media['favourites'],
                    'popularity' => $media['popularity'],
                    'status' => $media['status'],
                    'is_adult' => (int) $media['isAdult'],
                    'is_licensed' => (int) $media['isLicensed'],
                    'volumes' => $media['volumes'] ?? null,
                    'reviews' => $media['reviews']['pageInfo']['total'],
                    'start_date_y' => $media['startDate']['year'],
                    'start_date_m' => $media['startDate']['month'],
                    'start_date_d' => $media['startDate']['day'],
                    'end_date_y' => $media['endDate']['year'],
                    'end_date_m' => $media['endDate']['month'],
                    'end_date_d' => $media['endDate']['day'],
                    'cover_image' => $media['coverImage']['large'],
                    'status_current' => $statsCurrent,
                    'status_planning' => $statsPlanning,
                    'status_completed' => $statsCompleted,
                    'status_dropped' => $statsDropped,
                    'status_paused' => $statsPaused,
                    'synonyms' => $media['synonyms'],
                ];

                $uGenres = array_values(array_unique($media['genres']));
                $v['genres'] = $uGenres;

                // Collect genres
                if (\count($media['genres']) !== \count($uGenres)) {
                    $this->log->debug(
                        $mediaType . ' id:' . $media['id']
                            . ' has duplicated genres. Consider making a data submission'
                    );
                }

                // Collect tags
                $check = [];
                $uTags = [];
                foreach ($media['tags'] as $t) {
                    if (in_array($t['name'], $check, true)) {
                        $this->log->debug(
                            $mediaType . ' id:' . $media['id']
                                . ' has duplicated tags. Consider making a data submission'
                        );
                        continue;
                    }
                    $check[] = $t['name'];

                    $tag = [
                        'tag' => $t['name'],
                        'rank' => $t['rank'],
                        'is_spoiler' => (int) ($t['isMediaSpoiler'] || $t['isGeneralSpoiler']),
                    ];
                    $uTags[] = $tag;
                    $tag['media_id'] = $media['id'];
                }
                $v['tags'] = $uTags;

                // Create a unique list of sites so we don't end up with multiple "Official Site" rows
                $sites = [];
                foreach ($media['externalLinks'] as $l) {
                    $sites[] = $l['site'];
                }
                $sites = array_values(array_unique($sites));
                $v['external_links'] = $sites;

                // Collect MAL IDs
                if ($media['idMal'] !== null) {
                    $this->externalIds[] = [
                        'media_id' => $media['id'],
                        'service' => 'MyAnimeList',
                        'external_id' => $media['idMal'],
                        'source' => 'AniList',
                    ];
                }

                if (isset($media['studios'])) {
                // Collect studios/producers
                $uStudios = [];
                $uProducers = [];
                foreach ($media['studios']['edges'] as $s) {
                        if ($s['isMain'] === true && in_array($s['node']['name'], $uStudios, true)) {
                        $this->log->debug(
                                $mediaType . ' id:' . $media['id']
                                    . ' has duplicated studios. Consider reporting this.'
                        );
                        continue;
                    }
                        if ($s['isMain'] === false && in_array($s['node']['name'], $uProducers, true)) {
                        $this->log->debug(
                                $mediaType . ' id:' . $media['id']
                                    . ' has duplicated producers. Consider reporting this.'
                        );
                        continue;
                    }

                        if ($s['isMain'] === true) {
                        $uStudios[] = $s['node']['name'];
                    } else {
                        $uProducers[] = $s['node']['name'];
                    }
                }
                $v['studios'] = $uStudios;
                $v['producers'] = $uProducers;
                }

                $values[] = $v;

                $progressbar->advance();
            }

            try {
                $this->db->executeQuery(DBService::getBatchInsertFor('media', $values, 'media_pk', true));
            } catch (\Exception $e) {
                $this->log->error($e->getMessage());
                $this->log->error(DBService::getBatchInsertFor('media', $values, 'media_pk', true));
            }

            $this->db->commit();
        }
        $progressbar->finish();
        $this->output->write(PHP_EOL);
    }

    private function importRelations(string $mediaType): void
    {
        $file = self::IMPORT_DIR . 'data-' . $mediaType . '-relations.json';
        if (! file_exists($file)) {
            $this->output->writeln("<error>File: '$file' doesn't exist.</error>");
        }
        $data = json_decode(file_get_contents($file), true);
        $count = array_reduce($data, function ($carry, $item) {
            return $carry + \count($item);
        });
        $progressbar = new ProgressBar($this->output, $count);
        $chunks = array_chunk($data, self::CHUNK_SIZE, true);

        foreach ($chunks as $chunk) {
            $this->db->beginTransaction();
            $values = [];
            foreach ($chunk as $mediaId => $media) {
                foreach ($media as $m) {
                    $m['media_id'] = $mediaId;
                    $values[] = $m;
                }
            }
            $this->db->executeQuery(DBService::getBatchInsertFor('media_relations', $values));
            $progressbar->advance(\count($values));
            $this->db->commit();
        }
        $progressbar->finish();
        $this->output->write(PHP_EOL);
    }

    /** @param array<int, mixed> $data */
    private function importSubData(array $data, string $table): void
    {
        $progressbar = new ProgressBar($this->output, \count($data));
        $chunks = array_chunk($data, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            $this->db->beginTransaction();
            $this->db->executeQuery(DBService::getBatchInsertFor($table, $chunk));
            $progressbar->advance(\count($chunk));
            $this->db->commit();
        }
        $progressbar->finish();
        $this->output->write(PHP_EOL);
    }

    private function importCharacters(): void
    {
        $file = self::IMPORT_DIR . 'data-characters.json';
        if (! file_exists($file)) {
            $this->output->writeln("<error>File: '$file' doesn't exist.</error>");
        }

        $data = json_decode(file_get_contents($file), true);

        // Delete characters that no longer exist on AL
        $allCharacterIds = [];
        foreach ($data as $row) {
            $allCharacterIds[] = $row['id'];
        }
        $this->db->executeQuery(
            'DELETE FROM characters WHERE characters.id NOT IN (' . implode(',', $allCharacterIds) . ')'
        );
        unset($allCharacterIds);

        $progressbar = new ProgressBar($this->output, \count($data));
        $chunks = array_chunk($data, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            $this->db->beginTransaction();
            $values = [];
            foreach ($chunk as $row) {
                $values[] = [
                    'id' => $row['id'],
                    'name_first' => $row['name']['first'],
                    'name_middle' => $row['name']['middle'],
                    'name_last' => $row['name']['last'],
                    'name_native' => $row['name']['native'],
                    'description' => $row['description'],
                    'image' => $row['image']['medium'],
                    'gender' => $row['gender'],
                    'age' => $row['age'],
                    'date_of_birth_y' => $row['dateOfBirth']['year'],
                    'date_of_birth_m' => $row['dateOfBirth']['month'],
                    'date_of_birth_d' => $row['dateOfBirth']['day'],
                    'blood_type' => $row['bloodType'],
                    'favourites' => $row['favourites'],
                    'name_alternatives' => $row['name']['alternative'],
                    'name_alternatives_spoiler' => $row['name']['alternativeSpoiler']
                ];
            }
            $this->db->executeQuery(DBService::getBatchInsertFor('characters', $values, 'characters_pk', true));
            $progressbar->advance(\count($values));
            $this->db->commit();
        }
        $progressbar->finish();
        $this->output->write(PHP_EOL);

        $this->output->writeln('Importing character-media connections');
        $file = self::IMPORT_DIR . 'data-characters-relations.json';
        if (! file_exists($file)) {
            $this->output->writeln("<error>File: '$file' doesn't exist.</error>");
        }
        $data = json_decode(file_get_contents($file), true);
        $count = array_reduce($data, function ($carry, $item) {
            return $carry + \count($item);
        });
        $progressbar = new ProgressBar($this->output, $count);
        $chunks = array_chunk($data, self::CHUNK_SIZE, true);

        foreach ($chunks as $chunk) {
            $values = [];
            $this->db->beginTransaction();
            foreach ($chunk as $characterId => $media) {
                foreach ($media as $m) {
                    $values[] = [
                        'media_id' => $m['media_id'],
                        'character_id' => $characterId,
                        'role' => $m['role'],
                        'voice_actor_id' => $m['voice_actor_id'] ?? null,
                        'voice_actor_lang' => $m['voice_actor_lang'] ?? null,
                    ];
                }
            }
            $this->db->executeQuery(DBService::getBatchInsertFor('media_characters', $values));
            $progressbar->advance(\count($values));
            $this->db->commit();
        }
        $progressbar->finish();
        $this->output->write(PHP_EOL);
    }

    private function importStaff(): void
    {
        $file = self::IMPORT_DIR . 'data-staff.json';
        if (! file_exists($file)) {
            $this->output->writeln("<error>File: '$file' doesn't exist.</error>");
        }

        $data = json_decode(file_get_contents($file), true);

        $allStaffIds = [];
        foreach ($data as $row) {
            $allStaffIds[] = $row['id'];
        }
        $this->db->executeQuery('DELETE FROM staff WHERE staff.id NOT IN (' . implode(',', $allStaffIds) . ')');
        unset($allStaffIds);

        $progressbar = new ProgressBar($this->output, \count($data));
        $chunks = array_chunk($data, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            $this->db->beginTransaction();
            $values = [];
            foreach ($chunk as $row) {
                $values[] = [
                    'id' => $row['id'],
                    'name_first' => $row['name']['first'],
                    'name_middle' => $row['name']['middle'],
                    'name_last' => $row['name']['last'],
                    'name_native' => $row['name']['native'],
                    'image' => $row['image']['medium'],
                    'description' => $row['description'],
                    'gender' => $row['gender'],
                    'blood_type' => $row['bloodType'],
                    'years_active_from' => $row['yearsActive'][0] ?? null,
                    'years_active_until' => $row['yearsActive'][1] ?? null,
                    'home_town' => $row['homeTown'],
                    'date_of_birth_y' => $row['dateOfBirth']['year'],
                    'date_of_birth_m' => $row['dateOfBirth']['month'],
                    'date_of_birth_d' => $row['dateOfBirth']['day'],
                    'date_of_death_y' => $row['dateOfDeath']['year'],
                    'date_of_death_m' => $row['dateOfDeath']['month'],
                    'date_of_death_d' => $row['dateOfDeath']['day'],
                    'favourites' => $row['favourites'],
                    'name_alternatives' => $row['name']['alternative'],
                    'primary_occupations' => $row['primaryOccupations'],
                ];
            }
            $this->db->executeQuery(DBService::getBatchInsertFor('staff', $values, 'staff_pk', true));
            $progressbar->advance(\count($values));
            $this->db->commit();
        }
        $progressbar->finish();
        $this->output->write(PHP_EOL);

        $this->output->writeln('Importing staff-media connections');
        $file = self::IMPORT_DIR . 'data-staff-relations.json';
        if (! file_exists($file)) {
            $this->output->writeln("<error>File: '$file' doesn't exist.</error>");
        }
        $data = json_decode(file_get_contents($file), true);
        $count = array_reduce($data, function ($carry, $item) {
            return $carry + \count($item);
        });
        $progressbar = new ProgressBar($this->output, $count);

        $chunks = array_chunk($data, self::CHUNK_SIZE, true);

        foreach ($chunks as $chunk) {
            $this->db->beginTransaction();
            $values = [];
            foreach ($chunk as $staffId => $media) {
                foreach ($media as $m) {
                    $values[] = [
                        'media_id' => $m['media_id'],
                        'staff_id' => $staffId,
                        'role' => $m['role'],
                    ];
                }
            }
            // Unlike with genres, tags and studios we don't report duplicated media_staff entries for the time being
            // and just avoid duplicates through the insert
            $this->db->executeQuery(DBService::getBatchInsertFor('media_staff', $values, 'media_staff_pk'));
            $progressbar->advance(\count($values));
            $this->db->commit();
        }

        $progressbar->finish();
        $this->output->write(PHP_EOL);
    }

    private function importAWCLeaderboard(): int
    {
        $this->output->writeln('Importing AWC leaderboard');
        $this->db->executeQuery('DELETE FROM awc_leaderboard');

        $filename = 'data/import/awc-leaderboard.json';
        if (! file_exists($filename)) {
            $this->output->writeln('File ' . $filename . ' not found. Aborting import.');
        }
        $data = json_decode(file_get_contents($filename), true);
        $data = array_map(function ($row) {
            $row['username'] = $row['name'];
            unset($row['name']);

            return $row;
        }, $data);

        $this->db->beginTransaction();
        $this->db->executeQuery(DBService::getBatchInsertFor('awc_leaderboard', $data));
        $this->db->commit();

        return Command::SUCCESS;
    }

    private function importCommunityLists(): void
    {
        $file = self::IMPORT_DIR . 'data-community-lists.json';

        if (! file_exists($file)) {
            $this->output->writeln("<error>File: '$file' doesn't exist.</error>");
        }

        $data = json_decode(file_get_contents($file), true);
        $insValues = [];
        foreach ($data as $list => $entries) {
            foreach ($entries as $entry) {
                $insValues[] = [
                    'media_id' => $entry,
                    'community_list' => $list,
                ];
            }
        }

        $progressbar = new ProgressBar($this->output, \count($insValues));
        $chunks = array_chunk($insValues, self::CHUNK_SIZE);

        foreach ($chunks as $chunk) {
            $this->db->beginTransaction();
            $this->db->executeQuery(DBService::getBatchInsertFor('awc_community_lists', $chunk));
            $progressbar->advance(\count($chunk));
            $this->db->commit();
        }
        $progressbar->finish();
        $this->output->write(PHP_EOL);
    }

    private function importMediaTagCollection(): int
    {
        $this->output->writeln('Importing Media Tag Collection');
        $this->db->executeQuery('DELETE FROM media_tagcollection');

        $file = self::IMPORT_DIR . 'data-media-tag-collection.json';

        if (! file_exists($file)) {
            $this->output->writeln("<error>File: '$file' doesn't exist.</error>");
        }

        $data = json_decode(file_get_contents($file), true);

        $progressbar = new ProgressBar($this->output, \count($data));
        $this->db->beginTransaction();
        $values = [];
        foreach ($data as $row) {
            $row['tag_name'] = $row['name'];
            unset($row['name']);
            $values[] = $row;
        }
        $this->db->executeQuery(DBService::getBatchInsertFor('media_tagcollection', $values));
        $this->db->commit();
        $progressbar->finish();
        $this->output->write(PHP_EOL);

        return Command::SUCCESS;
    }

    private function importGamblersBotPicks(): void
    {
        $file = self::IMPORT_DIR . 'awc-gamblers-bot-picks.json';

        if (! file_exists($file)) {
            $this->output->writeln("<error>File: '$file' doesn't exist.</error>");
        }

        $data = json_decode(file_get_contents($file), true);
        $progressbar = new ProgressBar($this->output, \count($data));

        $chunks = array_chunk($data, self::CHUNK_SIZE);
        foreach ($chunks as $chunk) {
            $this->db->beginTransaction();
            $this->db->executeQuery(DBService::getBatchInsertFor('awc_gamblers_bot_picks', $chunk));
            $progressbar->advance(\count($chunk));
            $this->db->commit();
        }
        $progressbar->finish();
        $this->output->write(PHP_EOL);
    }

    private function importAnimeShonCrossRefs(): int
    {
        $this->output->writeln('Importing Animeshon CrossRefs (MangaDex and MangaUpdates IDs)');
        $this->db->executeQuery("DELETE FROM media_external_ids WHERE source = 'Animeshon'");

        $file = Animeshon::CROSSREFS_FILE;
        if (! file_exists($file)) {
            $this->output->writeln("<error>File: '$file' doesn't exist.</error>");
        }

        $data = json_decode(file_get_contents($file), true);
        // We need an array of all existing MU IDs to avoid foreign key constraint issues
        $allMUIds = $this->db->executeQuery("SELECT id FROM mangaupdates")->fetchAllAssociative();
        $allMUIds = array_map(function ($x) {
            return $x['id'];
        }, $allMUIds);

        $allMALIds = $this->db->executeQuery(
            "SELECT external_id, media_id FROM media_external_ids WHERE service = 'MyAnimeList' ORDER BY external_id"
        )->fetchAllKeyValue();
        $allMDIds = $this->db->executeQuery(
            "SELECT external_id, media_id FROM media_external_ids WHERE service = 'MangaDex' ORDER BY external_id"
        )->fetchAllKeyValue();

        $forInserts = [];
        foreach ($data as $muID => $crossrefs) {
            $muID = (int) $muID;
            // Skip MU IDs we don't have, likely dead links
            if (! in_array($muID, $allMUIds, true)) {
                continue;
            }

            $muMapped = false;
            foreach ($crossrefs as $crossref) {
                $map = match ($crossref['service']) {
                    'MangaDex' => $allMDIds,
                    'MyAnimeList' => $allMALIds,
                    default => [],
                };
                if (! isset($map[$crossref['external_id']])) {
                    continue;
                }
                // In case we find a match on both MD and MAL, only add the insert for MU once
                if ($muMapped === false) {
                    $forInserts[] = [
                        'media_id' => $map[$crossref['external_id']],
                        'service' => 'MangaUpdates',
                        'external_id' => $muID,
                        'source' => 'Animeshon',
                    ];
                    $muMapped = true;
                }
                $forInserts[] = [
                    'media_id' => $map[$crossref['external_id']],
                    'service' => $crossref['service'],
                    'external_id' => $crossref['external_id'],
                    'source' => 'Animeshon',
                ];
            }
        }

        $progressbar = new ProgressBar($this->output, \count($forInserts));

        $chunks = array_chunk($forInserts, self::CHUNK_SIZE);
        foreach ($chunks as $chunk) {
            $this->db->beginTransaction();
            $this->db->executeQuery(DBService::getBatchInsertFor('media_external_ids', $chunk));
            $progressbar->advance(\count($chunk));
            $this->db->commit();
        }
        $progressbar->finish();
        $this->output->write(PHP_EOL);

        return Command::SUCCESS;
    }

    private function importMangaDexMappings(): int
    {
        $this->output->writeln('Importing MangaDex mappings (AL and MangaUpdates IDs');

        $file = MangaDex::MAPPINGS_FILE;
        if (! file_exists($file)) {
            $this->output->writeln("<error>File: '$file' doesn't exist.</error>");
        }

        $data = json_decode(file_get_contents($file), true);

        // Due to foreign key constraints we need to make sure AL actually still has the manga we're trying to link
        $allALIds = $this->db->executeQuery("SELECT media.id FROM media WHERE media_type = 'MANGA'")
            ->fetchAllAssociativeIndexed();
        $allALIds = array_keys($allALIds);

        $forInserts = [];
        foreach ($data as $mdId => $refs) {
            // The scraper retrieves links to either AL or MU but for the time being we only care about the
            // Manga that has at least an AL id. We can later on infer MD ids through our own MU-AL mappings over MU ids
            if (! array_key_exists('al', $refs)) {
                continue;
            }

            // Skip manga AL already deleted
            if (! in_array($refs['al'], $allALIds, true)) {
                //$this->output->writeln('The AL manga with id "' . $refs['al'] . '" no longer exists');
                continue;
            }

            // Link from AL to MangaDex
            $forInserts[$refs['al'] . '-' . $mdId] = [
                'media_id' => $refs['al'],
                'service' => 'MangaDex',
                'external_id' => $mdId,
                'source' => 'MangaDex',
            ];
            // Link from AL to MangaUpdates
            if (array_key_exists('mu', $refs)) {
                $forInserts[$refs['al'] . '-' . $refs['mu']] = [
                    'media_id' => $refs['al'],
                    'service' => 'MangaUpdates',
                    'external_id' => $refs['mu'],
                    'source' => 'MangaDex',
                ];
            }
        }

        // Delete old mappings
        $del = $this->db->createQueryBuilder();
        $del->delete('media_external_ids');
        $del->where($del->expr()->eq('source', "'MangaDex'"));
        $del->executeQuery();

        $progressbar = new ProgressBar($this->output, \count($forInserts));

        // Insert new mappings
        $chunks = array_chunk($forInserts, self::CHUNK_SIZE);
        foreach ($chunks as $chunk) {
            $this->db->beginTransaction();
            $this->db->executeQuery(DBService::getBatchInsertFor('media_external_ids', $chunk));
            $progressbar->advance(\count($chunk));
            $this->db->commit();
        }
        $progressbar->finish();
        $this->output->write(PHP_EOL);

        return Command::SUCCESS;
    }

    private function importMangaUpdatesSeries(): int
    {
        $file = MangaUpdates::DATA_FILE;
        if (! file_exists($file)) {
            $this->output->writeln("<error>File: '$file' doesn't exist.</error>");
        }

        $data = json_decode(file_get_contents($file), true);

        $forInserts = [];
        foreach ($data as $id => $row) {
            // Temporary workaround because the file contains non-allowed types in it
            if (! in_array($row['type'], MangaUpdates::MANGA_TYPES, true)) {
                continue;
            }
            $row['id'] = $id;
                        $row['last_updated'] = date('Y-m-d H:i:s', $row['last_updated']);
            $forInserts[] = $row;
        }

        $progressbar = new ProgressBar($this->output, \count($forInserts));

        $chunks = array_chunk($forInserts, self::CHUNK_SIZE);
        foreach ($chunks as $chunk) {
            $this->db->beginTransaction();
            $this->db->executeQuery(DBService::getBatchInsertFor('mangaupdates', $chunk, 'mangaupdates_pk', true));
            $progressbar->advance(\count($chunk));
            $this->db->commit();
        }
        $progressbar->finish();
        $this->output->write(PHP_EOL);

        return Command::SUCCESS;
    }

    private function clearTables(): void
    {
        $this->output->writeln('Clearing tables before import');
        $tables = [
            'awc_community_lists',
            'awc_requirement_specific_lists',
            'media_relations',
            'media_characters',
            'media_staff',
        ];
        $this->db->executeQuery('TRUNCATE TABLE ' . implode(',', $tables) . ' CASCADE;');

        // Since media_external_ids contains data from various sources we only delete the data that is imported
        // through this class
        $this->db->executeQuery("DELETE FROM media_external_ids WHERE source = 'AniList'");
    }
}
