<?php

declare(strict_types=1);

namespace AniTools\Util;

final readonly class RegEx
{
    private const VALID_DELIMITERS = ['/', '@', '#', '~'];

    // Contains the RegEx pattern formatted for use in PostgreSQL
    public string $postgresFormat;
    // Contains the pattern without delimiters
    public string $pattern;
    // Contains the RegEx flags, if any were passed
    public ?string $flags;

    public function __construct(string $input)
    {
        // To keep our sanity, we require users to use delimiters in their patterns
        $delimiter = $input[0];
        // The only delimiters we accept
        if (! in_array($delimiter, self::VALID_DELIMITERS)) {
            throw new \InvalidArgumentException(
                'The pattern needs to be wrapped in one of the following delimiters: '
                . implode(', ', self::VALID_DELIMITERS)
            );
        }
        $parts = array_values(array_filter(explode($delimiter, $input)));
        $pattern = $parts[0];

        // Given that postgres has different flags than PHP we only test the validity of the pattern itself
        // without the flags
        if(@preg_match('/' . $pattern . '/', '') === false) {
            throw new \InvalidArgumentException('Invalid RegEx pattern');
        }

        $this->pattern = $pattern;

        // Check if any options were passed and prepend them to the pattern in a way postgres can work with
        // /pattern/i => (?i)pattern
        if (isset($parts[1])) {
            // Also make sure that the flags that were passed are actually supported by postgres
            // https://www.postgresql.org/docs/current/functions-matching.html#POSIX-METASYNTAX
            // Although in this particular case we only really see 'i' as useful
            if ($parts[1] !== 'i') {
                throw new \InvalidArgumentException('Currently only "i" is allowed as a flag');
            }
            $pattern = '(?' .  $parts[1] . ')' . $pattern;
            $this->flags = $parts[1];
        } else {
            $this->flags = null;
        }

        $this->postgresFormat = $pattern;
    }
}
