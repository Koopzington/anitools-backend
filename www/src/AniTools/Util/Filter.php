<?php

declare(strict_types=1);

namespace AniTools\Util;

final class Filter
{
    private const FILTER_TYPES = [
        'and' => 'array',
        'or' => 'array',
        'not' => 'array',
        'genre' => 'array_string',
        'studio' => 'array_string',
        'producer' => 'array_string',
        'externalLink' => 'array_string',
        'titleLike' => 'string_or_regex',
        'notesLike' => 'string',
        'episodesMin' => 'int',
        'episodesMax' => 'int',
        'volumesMin' => 'int',
        'volumesMax' => 'int',
        'totalRuntimeMin' => 'int',
        'totalRuntimeMax' => 'int',
        'showAdult' => 'bool',
        'hasReview' => 'bool',
        'format' => 'array_string',
        'source' => 'array_string',
        'country' => 'array_string',
        'airStatus' => 'array_string',
        'airingStart' => 'fuzzydate',
        'airingFinish' => 'fuzzydate',
        'season' => 'array_string',
        'year' => 'array_int_or_range',
        'mcCountMin' => 'int',
        'mcCountMax' => 'int',
        'voiceActor' => 'array_int',
        'staff' => 'array_int',
        'tag' => 'array_string',
        'awcCommunityList' => 'array_string',
        'onlyScanlated' => 'bool',
        'muPublisher' => 'array_string',
        'muPublication' => 'array_string',
        'userList' => 'array_string',
        'nameLike' => 'string_or_regex',
        'bloodType' => 'array_string',
        'gender' => 'array_string',
        'birthdayFrom' => 'fuzzydate',
        'birthdayUntil' => 'fuzzydate',
        'deathdayFrom' => 'fuzzydate',
        'deathdayUntil' => 'fuzzydate',
        'userStartFrom' => 'fuzzydate',
        'userFinishUntil' => 'fuzzydate',
        'meanScoreMin' => 'int',
        'meanScoreMax' => 'int',
        'avgScoreMin' => 'int',
        'avgScoreMax' => 'int',
    ];

    /** @var array<string, array<int, string>> */
    private $filterEnums;

    /** @var array<string, mixed> */
    private array $filters = [];

    /**
     * @param array<string, mixed[]> $filterValues
     * @param array<string, mixed> $rawFilters
     */
    public function __construct(array $filterValues, array $rawFilters)
    {
        $this->filterEnums = [];

        if (isset($filterValues['format'])) {
            $this->filterEnums['format'] = $filterValues['format'];
        }
        if (isset($filterValues['source'])) {
            $this->filterEnums['source'] = $filterValues['source'];
        }
        if (isset($filterValues['country_of_origin'])) {
            $this->filterEnums['country'] = $filterValues['country_of_origin'];
        }
        if (isset($filterValues['status'])) {
            $this->filterEnums['airStatus'] = $filterValues['status'];
        }
        if (isset($filterValues['season'])) {
            $this->filterEnums['season'] = $filterValues['season'];
        }
        if (isset($filterValues['genres'])) {
            $this->filterEnums['genre'] = $filterValues['genres'];
        }
        if (isset($filterValues['tags'])) {
            $this->filterEnums['tag'] = array_merge(...array_values($filterValues['tags']));
        }
        if (isset($filterValues['external_links'])) {
            $this->filterEnums['externalLink'] = $filterValues['external_links'];
        }
        if (isset($filterValues['awc_community_lists'])) {
            $this->filterEnums['awcCommunityList'] = $filterValues['awc_community_lists'];
        }

        foreach ($rawFilters as $filterType => $values) {
            if (! isset(self::FILTER_TYPES[$filterType])) {
                throw new \InvalidArgumentException('Filter type "' . $filterType . '" is not supported');
            }

            if (in_array($filterType, ['and', 'or', 'not'])) {
                $this->filters[$filterType] = new Filter($filterValues, $values);

                continue;
            }

            $dataType = self::FILTER_TYPES[$filterType];

            $filteredValue = match ($dataType) {
                'int' => (int) $values,
                'string' => (string) $values,
                'string_or_regex' => [ 'regex' => (bool) $values['regex'], 'value' => (string) $values['value'] ],
                'bool' => $values === 'true' || $values === true ? true : false,
                'array' => $values,
                'array_int' => $this->filterArray($filterType, 'int', $values),
                'array_int_or_range' => $this->filterArray($filterType, 'int_or_range', $values),
                'array_string' => $this->filterArray($filterType, 'string', $values),
                'fuzzydate' => (string) $values,
            };

            // Check the values against the list of valid values if present
            if (isset($this->filterEnums[$filterType])) {
                if (str_contains($dataType, 'array_')) {
                    $isValid = $this->validateArray($filterType, $filteredValue);
                } else {
                    $isValid = in_array($filteredValue, $this->filterEnums[$filterType]);
                }
                if (! $isValid) {
                    throw new \InvalidArgumentException('Invalid values for filter type "' . $filterType . '"');
                }
            } elseif ($dataType === 'fuzzydate') {
                if (! $this->validateFuzzyDate($filteredValue)) {
                    throw new \InvalidArgumentException('Invalid values for filter type "' . $filterType . '"');
                }
            }

            $this->filters[$filterType] = $filteredValue;
        }
    }

    /** @return array<string, mixed> */
    public function getValues(): array
    {
        $output = [];
        foreach ($this->filters as $key => $value) {
            if ($value instanceof Filter) {
                $output[$key] = $value->getValues();
            } else {
                $output[$key] = $value;
            }
        }

        return $output;
    }

    /**
     * @param 'int' | 'string' $type
     * @param array<'and' | 'or' | 'not' | 'tagPercentageMin' | 'tagPercentageMax', array<int, int | string>> $values
     * @return array<'and' | 'or' | 'not' | 'tagPercentageMin' | 'tagPercentageMax', array<int, int | string>>
     */
    private function filterArray(string $filterType, string $type, array $values): array
    {
        $filtered = [];

        foreach ($values as $andOrNot => $vs) {
            // Convert the minimum tag percentage to a number
            if ($filterType === 'tag' && ($andOrNot === 'tagPercentageMin' || $andOrNot === 'tagPercentageMax')) {
                $filtered[$andOrNot] = (int) $vs;
                continue;
            }
            foreach ($vs as $v) {
                $filtered[$andOrNot][] = match ($type) {
                    'int' => (int) $v,
                    'int_or_range' => $this->processSingleOrRangeValue('int', $v),
                    'string' => (string) $v,
                };
            }
        }

        return $filtered;
    }

    /**
     * @param 'int' | 'string' $type
     */
    private function processSingleOrRangeValue(string $type, string $value): string | int | IntRange
    {
        $exp = explode('-', $value);
        if (\count($exp) === 1) {
            return match ($type) {
                'int' => (int) $value,
                'string' => (string) $value,
            };
        }

        return match ($type) {
            'int' => new IntRange((int) $exp[0], (int) $exp[1])
        };
    }

    /** 
     * Checks whether the passed values are actually in the list of valid values
     * @param array<'and' | 'or' | 'not' | 'tagPercentageMin' | 'tagPercentageMax', array<int, int | string>> $values
     * */
    private function validateArray(string $filterType, array $values): bool
    {
        foreach ($values as $groupName => $group) {
            if ($filterType === 'tag' && ($groupName === 'tagPercentageMin' || $groupName === 'tagPercentageMax')) {
                // $group is actually a number from 1-100 here
                if ($group < 0 || $group > 100) {
                    return false;
                }
                // Skip to next group as this is the only validation needed
                continue;
            }
            foreach ($group as $v) {
                if (! in_array($v, $this->filterEnums[$filterType])) {
                    return false;
                }
            }
        }

        return true;
    }

    private function validateFuzzyDate(string $value): bool
    {
        // Must be along the lines of 2000-01-01 or *-01-*, any other character isn't allowed
        if (! preg_match('/(\d{4}|\*)-(\d{2}|\*)-(\d{2}|\*)/', $value)) {
            return false;
        }

        list($year, $month, $day) = explode('-', $value);

        // Out of bounds check
        if ($month !== '*' && ($month < 1 || $month > 12)) {
            return false;
        }

        // Out of bounds check
        if ($day !== '*' && ($day < 1 || $day > 31)) {
            return false;
        }

        // Check if full date is valid
        if (is_numeric($year) && is_numeric($month) && is_numeric($day)) {
            if (! checkdate((int) $month, (int) $day, (int) $year)) {
                return false;
            }
        }

        return true;
    }
}
