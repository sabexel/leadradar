<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Email verifier result (MillionVerifier mapping).
 */
enum EmailVerificationStatus: string
{
    case Unverified = 'unverified';
    case Ok = 'ok';
    case CatchAll = 'catch_all';
    case Unknown = 'unknown';
    case Invalid = 'invalid';
    case Disposable = 'disposable';
    case Error = 'error';
}
