<?php

declare(strict_types=1);

namespace App\Services\Classification;

use App\DTOs\JobBoards\JobAdData;
use Illuminate\Support\Str;

/**
 * Local: spots recruitment agencies, which are never leads (docs/04, docs/06).
 */
final class RecruiterDetector
{
    /**
     * Whole-word patterns matched against the lowercased employer name.
     */
    private const NAME_PATTERNS = [
        'recruit\w*',
        'resourcing',
        'talent',
        'staffing',
        'search partners?',
        'executive search',
        'head ?hunt\w*',
        'people',
        'personnel',
        'careers (?:ltd|limited)',
        'selection (?:ltd|limited)',
        'appointments',
        'employment (?:agency|services|ltd|limited)',
    ];

    /**
     * Ad-text phrases that mean "posted on behalf of someone else". Singular "client" only —
     * agencies talk about "our clients" all the time.
     */
    private const AD_PHRASES = [
        'on behalf of (?:our|my|a) client\b',
        '\b(?:our|my) client (?:is|are|has|have|based|requires|seeks|seeking|needs)\b',
        '\bhiring for (?:a|our|my) client\b',
        '\brecruiting for (?:a|our|my) client\b',
    ];

    /**
     * SIC 2007 codes for employment activities.
     */
    private const RECRUITER_SIC_CODES = ['78100', '78109', '78200', '78300'];

    public function isRecruiterName(string $employerName): bool
    {
        $name = Str::lower(Str::ascii($employerName));

        foreach (self::NAME_PATTERNS as $pattern) {
            if (preg_match('/\b'.$pattern.'\b/', $name) === 1) {
                return true;
            }
        }

        return false;
    }

    public function isRecruiterAd(JobAdData $ad): bool
    {
        if ($this->isRecruiterName($ad->employerName)) {
            return true;
        }

        $text = Str::lower($ad->title.' '.($ad->description ?? ''));

        foreach (self::AD_PHRASES as $pattern) {
            if (preg_match('/'.$pattern.'/', $text) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>|null  $sicCodes
     */
    public function hasRecruiterSicCode(?array $sicCodes): bool
    {
        return $sicCodes !== null && array_intersect($sicCodes, self::RECRUITER_SIC_CODES) !== [];
    }
}
