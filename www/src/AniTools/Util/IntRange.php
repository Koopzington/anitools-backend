<?php

declare(strict_types=1);

namespace AniTools\Util;

final readonly class IntRange
{
    public function __construct(
        public int $min,
        public int $max,
    ) {
        if ($max < $min) {
            throw new \InvalidArgumentException('max value must not be larger than min value');
        }
    }
}
