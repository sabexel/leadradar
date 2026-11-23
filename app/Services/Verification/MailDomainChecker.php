<?php

declare(strict_types=1);

namespace App\Services\Verification;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Free pre-check before a paid verification (D121): can this domain receive mail at all? A DNS lookup for MX
 * records (falling back to an A record, the RFC 5321 implicit MX). Cached per domain for a day. A DNS failure
 * (timeout) never blocks — only a clear "no mail host" answer does.
 */
class MailDomainChecker
{
    public function acceptsMail(string $domain): bool
    {
        if (! (bool) config('services.millionverifier.mx_precheck', true)) {
            return true;
        }

        $domain = Str::lower(trim($domain, ". \t"));

        if ($domain === '') {
            return false;
        }

        return (bool) Cache::remember("mx-check:{$domain}", now()->addDay(), fn (): bool => $this->lookup($domain));
    }

    /**
     * The DNS question itself; separate so tests can replace it.
     */
    protected function lookup(string $domain): bool
    {
        $fqdn = $domain.'.';

        if (@checkdnsrr($fqdn, 'MX')) {
            return true;
        }

        // No MX: mail may still go to the domain's own address (implicit MX) — but a domain that resolves to
        // nothing at all is a dead end.
        return @checkdnsrr($fqdn, 'A') || @checkdnsrr($fqdn, 'AAAA');
    }
}
