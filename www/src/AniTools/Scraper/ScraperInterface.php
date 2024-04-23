<?php

declare(strict_types=1);

namespace AniTools\Scraper;

interface ScraperInterface
{
    public const SCRAPER_NAME = 'scraper';
    public const VALID_DATATYPES = [];

    public function scrape(string $dataType): int;
}
