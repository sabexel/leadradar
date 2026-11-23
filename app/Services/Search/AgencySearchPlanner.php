<?php

declare(strict_types=1);

namespace App\Services\Search;

use App\Models\Setting;

/**
 * Local: which agency searches to run today (D113). Every (country, city, phrase) combination of the enabled
 * markets forms one long list; each run takes the next `source.agency.queries_per_day` entries from a saved
 * cursor, so consecutive days search new cities and phrases. After a full pass the result page (Brave `offset`)
 * moves on, so the same search later returns the next agencies.
 */
final class AgencySearchPlanner
{
    public const MAX_OFFSET = 5;

    /**
     * @return list<array{phrase: string, city: string, country: string}>
     */
    public function combinations(): array
    {
        $markets = (array) config('leadradar.agency_markets', []);
        $default = array_values(array_filter((array) config('leadradar.agency_phrases', []), 'is_string'));
        $enabled = array_map('strtoupper', array_filter((array) Setting::get('source.agency.countries'), 'is_string'));
        $byCountry = [];

        foreach ($enabled as $country) {
            $market = $markets[$country] ?? null;

            if (! is_array($market)) {
                continue;
            }

            $cities = array_values(array_filter((array) ($market['cities'] ?? []), 'is_string'));
            $local = array_values(array_filter((array) ($market['phrases'] ?? []), 'is_string'));
            $phrases = array_values(array_unique([...$local, ...$default]));

            foreach ($phrases as $phrase) {
                foreach ($cities as $city) {
                    $byCountry[$country][] = ['phrase' => $phrase, 'city' => $city, 'country' => $country];
                }
            }
        }

        // Interleave countries so a day's searches spread over every market, not one country at a time.
        $list = [];
        $longest = $byCountry === [] ? 0 : max(array_map('count', $byCountry));

        for ($i = 0; $i < $longest; $i++) {
            foreach ($byCountry as $combos) {
                if (isset($combos[$i])) {
                    $list[] = $combos[$i];
                }
            }
        }

        return $list;
    }

    /**
     * The next searches, and moves the cursor past them.
     *
     * @return list<array{phrase: string, city: string, country: string, offset: int}>
     */
    public function nextBatch(): array
    {
        $combos = $this->combinations();
        $count = count($combos);
        $take = max(0, (int) Setting::get('source.agency.queries_per_day'));

        if ($count === 0 || $take === 0) {
            return [];
        }

        $cursor = max(0, (int) Setting::get('source.agency.cursor'));
        $batch = [];

        for ($i = 0; $i < min($take, $count); $i++) {
            $position = $cursor + $i;
            $batch[] = [...$combos[$position % $count], 'offset' => intdiv($position, $count) % self::MAX_OFFSET];
        }

        Setting::put('source.agency.cursor', ($cursor + count($batch)) % ($count * self::MAX_OFFSET));

        return $batch;
    }
}
