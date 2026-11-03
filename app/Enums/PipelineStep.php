<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Current or next pipeline step (docs/05 state machine).
 */
enum PipelineStep: string
{
    case RegistrySearch = 'registry_search';
    case RegistryProfile = 'registry_profile';
    case RegistryOfficers = 'registry_officers';
    case Classify = 'classify';
    case FindWebsite = 'find_website';
    case Crawl = 'crawl';
    case BuildEmailCandidates = 'build_email_candidates';
    case VerifyEmails = 'verify_emails';
    case FindLinkedIn = 'find_linkedin';
    case Qualify = 'qualify';
    case Done = 'done';
}
