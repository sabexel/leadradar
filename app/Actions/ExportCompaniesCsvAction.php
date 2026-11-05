<?php

declare(strict_types=1);

namespace App\Actions;

use App\Enums\EmailOrigin;
use App\Enums\EmailVerificationStatus;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use Illuminate\Database\Eloquent\Builder;

/**
 * Local, sync: write leads as CSV in the owner's outreach-sheet column order (docs/07 "Export format"), so rows
 * can be pasted straight into the sheet, in the order of the query. Streams to a handle — nothing is written to disk (exports are never kept).
 */
final class ExportCompaniesCsvAction
{
    public const HEADER = [
        'S.no', 'Company Name', 'Website', 'Location', 'Company House no', 'Company contact email', 'Company careers email',
        'Founder 1 First Name', 'Founder 1 Last Name', 'Founder 1 LinkedIn', 'Founder 1 email', 'Founder 1 email test',
        'Founder 2 First Name', 'Founder 2 Last Name', 'Founder 2 LinkedIn', 'Founder 2 email', 'Founder 2 email test',
        'Score', 'Company type', 'Signal', 'Signal URL',
    ];

    /**
     * @param  Builder<Company>  $query  already filtered and sorted
     * @param  resource  $handle
     * @return int rows written (header excluded)
     */
    public function write(Builder $query, $handle): int
    {
        // UTF-8 BOM so Excel opens accents correctly (the importer strips it again).
        fwrite($handle, "\u{FEFF}");
        fputcsv($handle, self::HEADER, ',', '"', '');

        $n = 0;

        $query->with([
            'founders.email',
            'contactEmail',
            'careersEmail',
            'signals' => fn ($q) => $q->latest('posted_at')->latest('id'),
        ])->lazy(200)->each(function (Company $company) use ($handle, &$n): void {
            fputcsv($handle, $this->row(++$n, $company), ',', '"', '');
        });

        return $n;
    }

    /**
     * @return list<string>
     */
    public function row(int $number, Company $company): array
    {
        $founder = fn (int $position): ?Person => $company->founders->firstWhere('position', $position);
        $signal = $company->signals->first();

        return [
            (string) $number,
            $this->text($company->name),
            $this->text($company->website),
            $this->text($company->location),
            // Kept as text so leading zeros survive a spreadsheet (docs/07).
            $company->registration_number === null ? '' : '="'.str_replace('"', '', $company->registration_number).'"',
            $this->text($company->contactEmail?->email),
            $this->text($company->careersEmail?->email),
            ...$this->founderColumns($founder(1)),
            ...$this->founderColumns($founder(2)),
            $company->score === null ? '' : number_format((float) $company->score, 1, '.', ''),
            $this->text(ucfirst(str_replace('_', ' ', $company->company_type->value))),
            $this->text($signal?->title),
            $this->text($signal?->url),
        ];
    }

    /**
     * docs/07: Verified (ok) · Catch-all · Unknown · Invalid · Generated (an unverified pattern guess). See D93.
     */
    public function emailTest(?EmailAddress $email): string
    {
        if ($email === null) {
            return '';
        }

        return match ($email->verification_status) {
            EmailVerificationStatus::Ok => 'Verified',
            EmailVerificationStatus::CatchAll => 'Catch-all',
            EmailVerificationStatus::Invalid, EmailVerificationStatus::Disposable => 'Invalid',
            EmailVerificationStatus::Unverified => $email->origin === EmailOrigin::Generated ? 'Generated' : 'Unknown',
            EmailVerificationStatus::Unknown, EmailVerificationStatus::Error => 'Unknown',
        };
    }

    /**
     * @return list<string>
     */
    private function founderColumns(?Person $person): array
    {
        if ($person === null) {
            return ['', '', '', '', ''];
        }

        return [
            $this->text($person->first_name),
            $this->text($person->last_name),
            $this->text($person->linkedin_url),
            $this->text($person->email?->email),
            $this->emailTest($person->email),
        ];
    }

    /**
     * Neutralise spreadsheet formulas in scraped text (CSV injection): a cell starting with = + - @ or a control
     * character is prefixed with an apostrophe.
     */
    private function text(?string $value): string
    {
        $value = trim((string) $value);

        return preg_match('/^[=+\-@\t\r]/', $value) === 1 ? "'".$value : $value;
    }
}
