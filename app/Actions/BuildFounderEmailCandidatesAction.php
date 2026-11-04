<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EmailKind;
use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Enums\PipelineOutcome;
use App\Enums\PipelineStep;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Services\Email\EmailPatternService;
use Illuminate\Support\Facades\DB;

/**
 * Local, sync (`build_email_candidates` step): pattern-generated addresses for Founder 1 and 2, ready
 * for verification. Rows are created in try-order, so verification can walk them by id (P1.9).
 */
final class BuildFounderEmailCandidatesAction
{
    public function __construct(private readonly EmailPatternService $patterns) {}

    public function run(Company $company): Company
    {
        $founders = $company->founders()->get();
        $preferred = $this->confirmedPattern($company);
        $created = 0;
        $skipped = [];

        DB::transaction(function () use ($company, $founders, $preferred, &$created, &$skipped): void {
            foreach ($founders as $founder) {
                if ($this->hasKnownEmail($founder)) {
                    $skipped[] = $founder->position; // a real address was found on the site, or already verified

                    continue;
                }

                foreach ($this->patterns->candidates($founder, (string) $company->domain, $preferred) as $email) {
                    $row = EmailAddress::query()->firstOrCreate(
                        ['company_id' => $company->id, 'email' => $email],
                        [
                            'person_id' => $founder->id,
                            'kind' => EmailKind::Person,
                            'origin' => EmailOrigin::Generated,
                            'source_url' => null,
                        ],
                    );

                    $created += $row->wasRecentlyCreated ? 1 : 0;
                }
            }
        });

        $company->update(['pipeline_step' => PipelineStep::VerifyEmails]);
        $company->recordEvent(PipelineStep::BuildEmailCandidates, PipelineOutcome::Ok, "{$created} candidate email(s) for {$founders->count()} founder(s)", [
            'created' => $created,
            'founders' => $founders->count(),
            'skipped_positions' => $skipped,
            'preferred_pattern' => $preferred,
        ]);

        return $company;
    }

    private function hasKnownEmail(Person $founder): bool
    {
        return $founder->emailAddresses()
            ->where(fn ($q) => $q->where('origin', EmailOrigin::Crawled)->orWhere('verification_status', EmailVerificationStatus::Ok))
            ->exists();
    }

    /**
     * The company's address convention, learnt from a person whose address is confirmed (verified `ok`, or
     * published on the company's own site). Most common pattern wins.
     */
    private function confirmedPattern(Company $company): ?string
    {
        $confirmed = EmailAddress::query()
            ->where('company_id', $company->id)
            ->whereNotNull('person_id')
            ->where(fn ($q) => $q->where('origin', EmailOrigin::Crawled)->orWhere('verification_status', EmailVerificationStatus::Ok))
            ->with('person')
            ->get();

        $counts = [];

        foreach ($confirmed as $email) {
            $pattern = $email->person === null ? null : $this->patterns->patternOf($email->person, $email->email);

            if ($pattern !== null) {
                $counts[$pattern] = ($counts[$pattern] ?? 0) + 1;
            }
        }

        if ($counts === []) {
            return null;
        }

        arsort($counts);

        return (string) array_key_first($counts);
    }
}
