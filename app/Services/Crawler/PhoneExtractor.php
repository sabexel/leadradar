<?php

declare(strict_types=1);

namespace App\Services\Crawler;

/**
 * Local: phone and WhatsApp numbers a company publishes on its own site (D131) — only from `tel:` links and
 * `wa.me` / `api.whatsapp.com` links, never guessed from free text (which is full of other numbers).
 */
final class PhoneExtractor
{
    /**
     * @param  list<array{href: string, text: string}>  $links
     * @return array{phones: list<string>, whatsapp: string|null}
     */
    public function extract(array $links): array
    {
        $phones = [];
        $whatsapp = null;

        foreach ($links as $link) {
            $href = trim($link['href']);

            if (stripos($href, 'tel:') === 0) {
                $number = $this->normalise(rawurldecode(substr($href, 4)));

                if ($number !== null) {
                    $phones[$number] = true;
                }

                continue;
            }

            if ($whatsapp === null && preg_match('#(?:wa\.me/|whatsapp\.com/send/?\?(?:[^"\s]*&)?phone=)\+?(\d{7,15})#i', $href, $m) === 1) {
                $whatsapp = '+'.$m[1];
            }
        }

        return ['phones' => array_slice(array_keys($phones), 0, 5), 'whatsapp' => $whatsapp];
    }

    /**
     * A leading + (or 00) and 7–15 digits (E.164 length); anything else is not a phone number.
     */
    private function normalise(string $raw): ?string
    {
        $raw = trim($raw);
        $digits = (string) preg_replace('/\D/', '', $raw);

        if (strlen($digits) < 7 || strlen($digits) > 15) {
            return null;
        }

        return str_starts_with($raw, '+') || str_starts_with($raw, '00') ? '+'.ltrim($digits, '0') : $digits;
    }
}
