<?php

namespace AniTools\Util;

enum MediaType
{
    case ANIME;
    case MANGA;
    case CHARACTER;
    case STAFF;

    public static function fromString(string $type): self
    {
        foreach (self::cases() as $case) {
            if ($case->name === $type) {
                return $case;
            }
        }

        throw new \InvalidArgumentException('Media type does not exist');
    }
}