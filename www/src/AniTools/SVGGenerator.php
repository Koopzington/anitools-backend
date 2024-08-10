<?php

declare(strict_types=1);

namespace AniTools;

use Doctrine\DBAL\Connection;
use Monolog\Logger;

final class SVGGenerator
{
    private const RANK_COLORS = [
        "Challenger" => "#000000",
        "Novice" => "#C0C0C0",
        "Veteran" => "#006400",
        "Ace" => "#FF0000",
        "Elite" => "#FFA500",
        "Extreme" => "#FFD700",
        "Legendary" => "#003366",
        "Fabled" => "#800080",
        "Eternal" => "#FFC0CB",
        "Demigod" => "#90EE90",
        "Divine" => "#0000FF",
        "Celestial" => "#FFFFED",
        "Omniscient" => "#87CEFA",
    ];

    private Connection $db;
    private Logger $log;

    public function __construct(Connection $db, Logger $logger)
    {
        $this->db = $db;
        $this->log = $logger;
    }

    public function generate(string $username): string
    {
        $svg = file_get_contents('data/awc-rank-base.svg');

        $sel = $this->db->createQueryBuilder();
        $sel->select(
            'place',
            'points',
            'rank',
        );
        $sel->from('awc_leaderboard');
        $sel->where($sel->expr()->eq('lower(username)', 'lower(:username)'));
        $result = $this->db->executeQuery((string) $sel, ['username' => $username]);
        if ($result->rowCount() === 1) {
            $row = $result->fetchAssociative();
            $this->log->debug('Signature requested: ' . implode(' - ', [$username, $row['points'], $row['rank']]));
            $svg = str_replace('$username$', $username, $svg);
            $svg = str_replace('$placement$', (string) $row['place'], $svg);
            $digits = (string) strlen((string) $row['place']);
            $svg = str_replace('$digits$', $digits, $svg);
            $svg = str_replace('$rank$', $row['rank'], $svg);
            $svg = str_replace('$points$', (string) $row['points'], $svg);
            $svg = str_replace('$rankColor$', self::RANK_COLORS[$row['rank']], $svg);
            $svg = str_replace('$placementColor$', ($row['points'] >= 405 ? 'black' : 'white'), $svg);
        }

        return $svg;
    }
}
