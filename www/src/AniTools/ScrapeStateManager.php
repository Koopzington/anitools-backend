<?php

declare(strict_types=1);

namespace AniTools;

final class ScrapeStateManager
{
    public const SCRAPE_STATE_FILE = 'data/other/scrape_state.json';

    public static function updateScrapeState(string $key): void
    {
        $scrapeState = [];
        if (file_exists(self::SCRAPE_STATE_FILE)) {
            $scrapeState = json_decode(file_get_contents(self::SCRAPE_STATE_FILE), true);
        }
        $scrapeState[$key] = gmdate('Y-m-d H:i:s');
        file_put_contents(self::SCRAPE_STATE_FILE, json_encode($scrapeState, JSON_PRETTY_PRINT));
    }

    /**
     * @return array<string, string>
     */
    public static function getScrapeState(): array
    {
        $scrapeState = [];
        if (file_exists(self::SCRAPE_STATE_FILE)) {
            $scrapeState = json_decode(file_get_contents(self::SCRAPE_STATE_FILE), true);
        }

        return $scrapeState;
    }
}
