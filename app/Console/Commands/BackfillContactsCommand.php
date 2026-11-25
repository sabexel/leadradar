<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\BackfillContactsAction;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('leadradar:backfill-contacts {--recrawl=50 : leads to crawl again} {--owners=20 : owner searches (Brave queries)}')]
#[Description('Fill phone, WhatsApp, brand and owner for older agency/business leads, rejected ones included (D136)')]
final class BackfillContactsCommand extends Command
{
    public function handle(BackfillContactsAction $backfill): int
    {
        $counts = $backfill->run(max(0, (int) $this->option('recrawl')), max(0, (int) $this->option('owners')));

        $this->info("Crawling again: {$counts['recrawled']} · owner searches queued: {$counts['owner_searches']}. Run a queue worker to process them.");

        return self::SUCCESS;
    }
}
