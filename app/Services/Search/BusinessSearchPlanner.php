<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Setting;

/**
 * Local: which "{category} {city}" business searches to run today (D127). Same rotation as the agency planner:
 * every (country, city, category) combination of the enabled business markets, interleaved, a saved cursor, and
 * the next result page after each full pass.
 */
final class BusinessSearchPlanner
{
    public const MAX_OFFSET = 5;

    /**
     * @return list<array{phrase: string, city: string, country: string}>
     */
    public function combinations(): array
    {
        $markets = (array) config('leadradar.business_markets', []);
        $enabled = array_map('strtoupper', array_filter((array) Setting::get('source.business.countries'), 'is_string'));
        $byCity = [];

        foreach ($enabled as $country) {
            $market = $markets[$country] ?? null;

            if (! is_array($market)) {
                continue;
            }

            foreach (array_filter((array) ($market['cities'] ?? []), 'is_string') as $city) {
                foreach (array_filter((array) ($market['categories'] ?? []), 'is_string') as $category) {
                    $byCity["{$country}|{$category}"][] = ['phrase' => $category, 'city' => $city, 'country' => $country];
                }
            }
        }

        // Interleave categories so one day covers many kinds of business, not one category in every city (D129).
        $list = [];
        $longest = $byCity === [] ? 0 : max(array_map('count', $byCity));

        for ($i = 0; $i < $longest; $i++) {
            foreach ($byCity as $combos) {
                if (isset($combos[$i])) {
                    $list[] = $combos[$i];
                }
            }
        }

        return $list;
    }

    /**
     * @return list<array{phrase: string, city: string, country: string, offset: int}>
     */
    public function nextBatch(): array
    {
        $combos = $this->combinations();
        $count = count($combos);
        $take = max(0, (int) Setting::get('source.business.queries_per_day'));

        if ($count === 0 || $take === 0) {
            return [];
        }

        $cursor = max(0, (int) Setting::get('source.business.cursor'));
        $batch = [];

        for ($i = 0; $i < min($take, $count); $i++) {
            $position = $cursor + $i;
            $batch[] = [...$combos[$position % $count], 'offset' => intdiv($position, $count) % self::MAX_OFFSET];
        }

        Setting::put('source.business.cursor', ($cursor + count($batch)) % ($count * self::MAX_OFFSET));

        return $batch;
    }
}
