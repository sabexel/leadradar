<?php

declare(strict_types=1);

namespace App\Services\Classification;

use App\DTOs\Classification\Classification;
use App\DTOs\Classification\ClassificationInput;
use App\DTOs\Classification\DetectedSignal;
use App\Enums\CompanyType;
use App\Enums\SignalWeight;
use App\Services\Crawler\OffshoreSignalDetector;
use Illuminate\Support\Str;

/**
 * Local: agency / direct client / recruiter / offshore / unknown (docs/04). First match wins:
 *
 * 1. recruiter — recruiter name or employment-agency SIC code
 * 2. offshore  — a strong offshore signal, or two different medium ones
 * 3. agency    — agency language AND (an agency SIC code OR an explicit "digital/web/… agency" phrase)
 * 4. direct_client — hiring (has a job ad) with no agency language
 * 5. unknown
 */
final class CompanyClassifier
{
    /**
     * Software, IT consultancy, web, advertising and design activities. UK SIC 2007 and Dutch SBI 2008 codes share
     * these first four digits (62011/62012 and 6201 are both "software"), so one prefix list covers both (D110).
     */
    private const AGENCY_CODE_PREFIXES = ['6201', '6202', '6209', '6311', '7311', '7410'];

    private const AGENCY_LANGUAGE = [
        'agency', 'studio', 'our clients', 'for clients', 'client projects?', 'client work', 'client sites?',
        'our work', 'case stud(?:y|ies)', 'portfolio', 'white[- ]label',
        'onze klanten', 'voor klanten', 'opdrachtgevers', 'ons werk', 'klantprojecten',
    ];

    private const EXPLICIT_AGENCY = '(?:(?:digital|web|creative|development|software|design|marketing|branding|ecommerce|e-commerce) (?:agency|studio)'
        .'|(?:web|internet|software|ontwikkel|reclame|marketing|design|online|digitaal|digital|creatief|e-commerce) ?bureau)';

    public function __construct(
        private readonly RecruiterDetector $recruiters,
        private readonly OffshoreSignalDetector $offshore,
    ) {}

    public function classify(ClassificationInput $input): Classification
    {
        if ($this->recruiters->isRecruiterName($input->name)) {
            return new Classification(CompanyType::Recruiter, [], ['Recruiter name']);
        }

        if ($this->recruiters->hasRecruiterSicCode($input->sicCodes)) {
            return new Classification(CompanyType::Recruiter, [], ['Employment-agency SIC code']);
        }

        $offshoreSignals = $this->offshoreSignals($input);

        if ($this->isOffshore($offshoreSignals)) {
            return new Classification(CompanyType::Offshore, $offshoreSignals, ['Strong offshore signals']);
        }

        $text = Str::lower($input->allText());
        $agencyLanguage = $this->firstMatch($text, self::AGENCY_LANGUAGE);
        $explicitAgency = preg_match('/\b'.self::EXPLICIT_AGENCY.'\b/u', $text, $m) === 1 ? $m[0] : null;
        $agencySic = array_values(array_filter($input->sicCodes, fn (string $code): bool => in_array(substr($code, 0, 4), self::AGENCY_CODE_PREFIXES, true)));

        if ($explicitAgency !== null) {
            return new Classification(CompanyType::Agency, $offshoreSignals, ["Says \"{$explicitAgency}\""]);
        }

        if ($agencyLanguage !== null && $agencySic !== []) {
            return new Classification(CompanyType::Agency, $offshoreSignals, ["Agency language \"{$agencyLanguage}\"", 'SIC '.implode(', ', $agencySic)]);
        }

        if ($input->adTexts !== [] && $agencyLanguage === null) {
            return new Classification(CompanyType::DirectClient, $offshoreSignals, ['Hiring in-house, no agency language']);
        }

        return new Classification(CompanyType::Unknown, $offshoreSignals, ['Not enough evidence yet']);
    }

    /**
     * Agency phrases present in a text (stored per crawled page instead of the text itself, D59).
     *
     * @return list<string>
     */
    public function agencyTerms(string $text): array
    {
        $text = Str::lower($text);
        $terms = [];

        foreach ([self::EXPLICIT_AGENCY, ...self::AGENCY_LANGUAGE] as $pattern) {
            if (preg_match_all('/\b'.$pattern.'\b/u', $text, $m) > 0) {
                array_push($terms, ...$m[0]);
            }
        }

        return array_values(array_unique($terms));
    }

    /**
     * @return list<DetectedSignal>
     */
    private function offshoreSignals(ClassificationInput $input): array
    {
        $text = $input->siteOffshoreSignals;

        foreach ([...$input->adTexts, ...$input->siteTexts] as $chunk) {
            array_push($text, ...$this->offshore->detectInText($chunk));
        }

        return $this->offshore->combine($text, $this->offshore->detectOfficersAbroad($input->officerCountries));
    }

    /**
     * @param  list<DetectedSignal>  $signals
     */
    private function isOffshore(array $signals): bool
    {
        $strong = array_filter($signals, fn (DetectedSignal $s): bool => $s->weight === SignalWeight::Strong);
        $mediumTypes = array_unique(array_map(
            fn (DetectedSignal $s): string => $s->type,
            array_filter($signals, fn (DetectedSignal $s): bool => $s->weight === SignalWeight::Medium),
        ));

        return $strong !== [] || count($mediumTypes) >= 2;
    }

    /**
     * @param  list<string>  $patterns
     */
    private function firstMatch(string $text, array $patterns): ?string
    {
        foreach ($patterns as $pattern) {
            if (preg_match('/\b'.$pattern.'\b/u', $text, $m) === 1) {
                return $m[0];
            }
        }

        return null;
    }
}
