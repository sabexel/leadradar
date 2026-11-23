export type OutreachDraftInput = {
    companyName: string;
    product: string;
    type: string;
    website: string | null;
    emailConsentRequired: boolean;
    recipientName: string | null;
    recipientEmail: string;
    signalTitle: string | null;
    signalSource: string | null;
    reviewCount: number | null;
    techStack?: string[];
};

export type OutreachDraft = {
    to: string;
    subject: string;
    body: string;
    consentWarning: boolean;
};

/**
 * Client-side draft for manual send (Outlook / Mailmeteor). Editable in the popup.
 */
export function draftOutreachEmail(input: OutreachDraftInput): OutreachDraft {
    const first = firstName(input.recipientName);
    const greeting = first ? `Hi ${first},` : 'Hi,';
    const company = input.companyName.trim() || 'your company';

    if (input.product === 'website') {
        const reviews =
            input.reviewCount !== null && input.reviewCount > 0
                ? ` with ${input.reviewCount} Google reviews`
                : '';

        return {
            to: input.recipientEmail,
            subject: `${company} — quick note about a website`,
            body: [
                greeting,
                '',
                `I came across ${company}${reviews} on Google Maps and noticed there isn’t a website listed.`,
                '',
                'I help UK businesses get a clear, fast site that matches how they already win work offline — happy to share a couple of simple options if useful.',
                '',
                'If now isn’t the right time, no worries — just say so and I won’t follow up.',
                '',
                'Best regards,',
                'Webwayout',
                'https://webwayout.co.uk',
            ].join('\n'),
            consentWarning: input.emailConsentRequired,
        };
    }

    if (input.product === 'agency') {
        const stacks = offeredStacks(input.techStack ?? []);
        const stackText = stacks.length > 0 ? stacks.join(' / ') : 'web';
        const opening =
            input.signalTitle &&
            ['reed', 'adzuna'].includes(input.signalSource ?? '')
                ? `I noticed ${company} is hiring for “${input.signalTitle}”.`
                : input.website
                  ? `I came across ${company} (${input.website.replace(/^https?:\/\//, '')}) while looking at ${stackText} agencies.`
                  : `I came across ${company} while looking at ${stackText} agencies.`;

        return {
            to: input.recipientEmail,
            subject: `Extra ${stackText} hands for ${company}`,
            body: [
                greeting,
                '',
                opening,
                '',
                `Webwayout is a small UK dev team that takes white-label ${stackText} work from agencies when their own team is full — builds, fixes, plugins/apps and ongoing support, delivered under your brand.`,
                '',
                'If that could help with overflow or a backlog, I can send a short one-pager and a couple of examples. If it’s not relevant, just say so and I won’t follow up.',
                '',
                'Best regards,',
                'Webwayout',
                'https://webwayout.co.uk',
            ].join('\n'),
            consentWarning: input.emailConsentRequired,
        };
    }

    const hiring =
        input.signalTitle &&
        ['reed', 'adzuna'].includes(input.signalSource ?? '')
            ? `I noticed ${company} is hiring for “${input.signalTitle}”.`
            : input.signalSource === 'laravel_partners'
              ? `I saw ${company} on the official Laravel Partners directory.`
              : input.website
                ? `I’ve been looking at ${company} (${input.website.replace(/^https?:\/\//, '')}).`
                : `I’ve been looking at ${company}.`;

    const offer =
        input.type === 'agency'
            ? 'We provide white-label Laravel overflow for agencies (£200/day) when your team is full — same stack, under your brand.'
            : 'We provide contract Laravel capacity for product teams (from £250/day) when you need an extra pair of hands without a long hire.';

    return {
        to: input.recipientEmail,
        subject: `Laravel capacity for ${company}`,
        body: [
            greeting,
            '',
            hiring,
            '',
            offer,
            '',
            'If helpful, I can send a short one-pager or jump on a 15-minute call. If it’s not relevant, just say so and I’ll close the loop.',
            '',
            'Best regards,',
            'Webwayout',
            'https://webwayout.co.uk',
        ].join('\n'),
        consentWarning: input.emailConsentRequired,
    };
}

function firstName(full: string | null): string | null {
    if (!full) {
        return null;
    }

    const part = full.trim().split(/\s+/)[0] ?? '';

    return part === '' ? null : part;
}

const STACK_LABELS: Record<string, string> = {
    wordpress: 'WordPress',
    woocommerce: 'WooCommerce',
    shopify: 'Shopify',
    laravel: 'Laravel',
    php: 'PHP',
    magento: 'Magento',
    webflow: 'Webflow',
    bigcommerce: 'BigCommerce',
    drupal: 'Drupal',
};

/**
 * "offers:shopify" entries from the crawl (D114), as labels, at most three.
 */
function offeredStacks(stack: string[]): string[] {
    return stack
        .filter((s) => s.startsWith('offers:'))
        .map((s) => STACK_LABELS[s.slice('offers:'.length)])
        .filter((label): label is string => label !== undefined)
        .slice(0, 3);
}
