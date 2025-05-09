<?php

declare(strict_types=1);

namespace AniTools\Util;

final readonly class User
{
    public function __construct(
        public int $id,
        public string $userName,
    ) {
    }
}
