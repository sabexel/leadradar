<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\PageType;
use App\Models\Company;
use App\Models\CrawledPage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CrawledPage>
 */
class CrawledPageFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'url' => 'https://example.test/'.fake()->unique()->slug(2),
            'http_status' => 200,
            'fetched_at' => now(),
            'content_hash' => hash('sha256', fake()->sentence()),
            'page_type' => PageType::Other,
            'extracted' => ['emails' => [], 'signals' => []],
        ];
    }
}
