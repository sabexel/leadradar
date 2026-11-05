import { Head, router } from '@inertiajs/react';
import { ExternalLink, Linkedin, RotateCcw } from 'lucide-react';
import { useState } from 'react';
import {
    humanise,
    ScoreBadge,
    StatusBadge,
    VerificationBadge,
} from '@/components/lead-badges';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { cn } from '@/lib/utils';
import { index, retry, show, update } from '@/routes/companies';
import { reverify } from '@/routes/companies/emails';
import type {
    CompanyDetailProps,
    EmailDetail,
    Evidence,
    ScoreLine,
} from '@/types/company-detail';

/** docs/06 score lines and their items, in plain words. */
const LINE_LABELS: Record<string, string> = {
    laravel_evidence: 'Laravel evidence',
    timing: 'Timing',
    company_fit: 'Company fit',
    decision_maker_reach: 'Decision-maker reach',
    company_inboxes: 'Company inboxes',
};

const ITEM_LABELS: Record<string, string> = {
    job_ad: 'Laravel job ad',
    site_mention: 'Laravel named on their site',
    partner: 'Laravel Partner',
    site_runs_laravel: 'Their site runs Laravel / Livewire / Inertia',
    newest_ad: 'Newest Laravel ad',
    type: 'Company type',
    small_company: 'Small company (accounts)',
    established: 'Incorporated 2+ years ago',
    founder_1_email: 'Founder 1 email',
    founder_1_linkedin: 'Founder 1 LinkedIn',
    founder_2_email: 'Founder 2 email',
    contact_ok: 'Contact inbox verified',
    careers_found: 'Careers inbox found',
};

export default function CompanyShow(props: CompanyDetailProps) {
    const { company } = props;

    return (
        <>
            <Head title={company.name} />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <Header {...props} />

                <div className="grid gap-4 lg:grid-cols-3">
                    <div className="flex flex-col gap-4 lg:col-span-2">
                        <CompanyCard company={company} />
                        <div className="grid gap-4 md:grid-cols-2">
                            {[1, 2].map((position) => (
                                <FounderCard
                                    key={position}
                                    position={position}
                                    companyId={company.id}
                                    founder={props.founders.find(
                                        (f) => f.position === position,
                                    )}
                                />
                            ))}
                        </div>
                        <EvidenceCard
                            laravel={props.laravelEvidence}
                            offshore={props.offshoreEvidence}
                            signals={props.signals}
                        />
                    </div>
                    <div className="flex flex-col gap-4">
                        <ScoreCard
                            breakdown={props.scoreBreakdown}
                            score={company.score}
                        />
                        <NotesCard
                            companyId={company.id}
                            notes={company.notes}
                        />
                    </div>
                </div>

                <TimelineCard {...props} />
            </div>
        </>
    );
}

CompanyShow.layout = (props: CompanyDetailProps) => ({
    breadcrumbs: [
        { title: 'Leads', href: index() },
        { title: props.company.name, href: show(props.company.id) },
    ],
});

function Header({ company, options }: CompanyDetailProps) {
    return (
        <div className="flex flex-wrap items-start justify-between gap-4">
            <div className="flex flex-col gap-2">
                <div className="flex flex-wrap items-center gap-2">
                    <h1 className="text-xl font-semibold">{company.name}</h1>
                    <ScoreBadge score={company.score} />
                    <StatusBadge status={company.status} />
                    <Badge variant="outline">{humanise(company.type)}</Badge>
                </div>
                <div className="flex flex-wrap items-center gap-x-4 gap-y-1 text-sm text-muted-foreground">
                    {company.rejectReason && (
                        <span>Rejected: {humanise(company.rejectReason)}</span>
                    )}
                    {company.website && (
                        <ExternalAnchor href={company.website}>
                            {company.domain ?? company.website}
                        </ExternalAnchor>
                    )}
                    {company.companiesHouseUrl && (
                        <ExternalAnchor href={company.companiesHouseUrl}>
                            Companies House
                        </ExternalAnchor>
                    )}
                    {company.qualifiedAt && (
                        <span>Qualified {formatDate(company.qualifiedAt)}</span>
                    )}
                    {company.contactedAt && (
                        <span>
                            First contacted {formatDate(company.contactedAt)}
                        </span>
                    )}
                </div>
            </div>

            <div className="flex items-center gap-2">
                <span className="text-sm text-muted-foreground">Outreach</span>
                <Select
                    value={company.outreachStatus}
                    onValueChange={(value) => {
                        if (
                            value === 'not_interested' &&
                            !window.confirm(
                                'Mark not interested? Their domain goes on the do-not-contact list.',
                            )
                        ) {
                            return;
                        }

                        router.patch(
                            update.url(company.id),
                            { outreach_status: value },
                            { preserveScroll: true },
                        );
                    }}
                >
                    <SelectTrigger
                        className="w-44"
                        aria-label="Outreach status"
                    >
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {options.outreach.map((option) => (
                            <SelectItem key={option.value} value={option.value}>
                                {option.label}
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
            </div>
        </div>
    );
}

function CompanyCard({ company }: { company: CompanyDetailProps['company'] }) {
    const address = company.registeredAddress;

    return (
        <Card>
            <CardHeader>
                <CardTitle>Company</CardTitle>
            </CardHeader>
            <CardContent>
                <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                    <Field label="Name">{company.name}</Field>
                    <Field label="Legal name">{company.legalName}</Field>
                    <Field label="Website">
                        {company.website && (
                            <ExternalAnchor href={company.website}>
                                {company.website}
                            </ExternalAnchor>
                        )}
                    </Field>
                    <Field label="Location">
                        {[company.location, company.country]
                            .filter(Boolean)
                            .join(' · ')}
                    </Field>
                    <Field label="Registration number">
                        {company.registrationNumber && (
                            <span className="font-mono">
                                {company.registrationNumber}
                            </span>
                        )}
                    </Field>
                    <Field label="Registry status">
                        {[
                            company.companyStatus,
                            company.companyKind,
                            company.accountsCategory,
                        ]
                            .filter(Boolean)
                            .join(' · ')}
                    </Field>
                    <Field label="Incorporated">
                        {company.incorporatedOn &&
                            formatDate(company.incorporatedOn)}
                    </Field>
                    <Field label="SIC codes">
                        {company.sicCodes.join(', ')}
                    </Field>
                    <Field label="Registered address" wide>
                        {address}
                    </Field>
                    <Field label="Contact email">
                        <EmailLine email={company.contactEmail} />
                    </Field>
                    <Field label="Careers email">
                        <EmailLine email={company.careersEmail} />
                    </Field>
                </dl>
            </CardContent>
        </Card>
    );
}

function FounderCard({
    position,
    founder,
    companyId,
}: {
    position: number;
    founder: CompanyDetailProps['founders'][number] | undefined;
    companyId: number;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Founder {position}</CardTitle>
                {founder && (
                    <CardDescription>
                        {humanise(founder.role)}
                        {founder.appointedOn &&
                            ` · appointed ${formatDate(founder.appointedOn)}`}
                    </CardDescription>
                )}
            </CardHeader>
            <CardContent className="flex flex-col gap-3 text-sm">
                {!founder ? (
                    <p className="text-muted-foreground">None found.</p>
                ) : (
                    <>
                        <div className="flex items-center gap-2">
                            <span className="font-medium">{founder.name}</span>
                            {founder.sourceUrl && (
                                <SourceLink
                                    href={founder.sourceUrl}
                                    label={humanise(founder.source)}
                                />
                            )}
                        </div>

                        <div className="flex items-center gap-2">
                            <Linkedin className="size-4 text-muted-foreground" />
                            {founder.linkedinUrl ? (
                                <ExternalAnchor href={founder.linkedinUrl}>
                                    {founder.linkedinUrl.replace(
                                        /^https?:\/\/(www\.)?/,
                                        '',
                                    )}
                                </ExternalAnchor>
                            ) : (
                                <span className="text-muted-foreground">
                                    Not found
                                </span>
                            )}
                        </div>

                        <div>
                            <div className="mb-1 text-xs text-muted-foreground">
                                Email
                            </div>
                            <EmailLine
                                email={founder.email}
                                companyId={companyId}
                            />
                        </div>

                        {founder.candidates.length > 0 && (
                            <div>
                                <div className="mb-1 text-xs text-muted-foreground">
                                    Candidates tried
                                </div>
                                <ul className="flex flex-col gap-1">
                                    {founder.candidates.map((candidate) => (
                                        <li
                                            key={candidate.id}
                                            className={cn(
                                                'flex flex-wrap items-center gap-2',
                                                candidate.id ===
                                                    founder.email?.id &&
                                                    'font-medium',
                                            )}
                                        >
                                            <span className="truncate">
                                                {candidate.email}
                                            </span>
                                            <VerificationBadge
                                                status={candidate.status}
                                            />
                                            <span className="text-xs text-muted-foreground">
                                                {humanise(candidate.origin)}
                                            </span>
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        )}
                    </>
                )}
            </CardContent>
        </Card>
    );
}

function EmailLine({
    email,
    companyId,
}: {
    email: EmailDetail | null;
    companyId?: number;
}) {
    const [sent, setSent] = useState(false);

    if (!email) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <div className="flex flex-wrap items-center gap-2">
            <span className="break-all">{email.email}</span>
            <VerificationBadge status={email.status} />
            {email.sourceUrl ? (
                <SourceLink
                    href={email.sourceUrl}
                    label={humanise(email.origin)}
                />
            ) : (
                <span className="text-xs text-muted-foreground">
                    {humanise(email.origin)}
                </span>
            )}
            {companyId !== undefined && (
                <Button
                    size="sm"
                    variant="outline"
                    className="h-6 px-2 text-xs"
                    disabled={sent}
                    onClick={() =>
                        router.post(
                            reverify.url({
                                company: companyId,
                                emailAddress: email.id,
                            }),
                            {},
                            {
                                preserveScroll: true,
                                onSuccess: () => setSent(true),
                            },
                        )
                    }
                >
                    {sent ? 'Queued' : 'Re-verify'}
                </Button>
            )}
        </div>
    );
}

function ScoreCard({
    breakdown,
    score,
}: {
    breakdown: CompanyDetailProps['scoreBreakdown'];
    score: number | null;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Score breakdown</CardTitle>
                {breakdown && (
                    <CardDescription>
                        {breakdown.total.toFixed(1)} / 10
                        {breakdown.failedGate &&
                            ` · failed gate: ${humanise(breakdown.failedGate)}`}
                    </CardDescription>
                )}
            </CardHeader>
            <CardContent>
                {!breakdown ? (
                    <p className="text-sm text-muted-foreground">
                        {score === null
                            ? 'Not scored yet — scoring runs at the end of the pipeline.'
                            : 'No breakdown stored for this score. Re-run scoring with php artisan leadradar:rescore.'}
                    </p>
                ) : (
                    <div className="flex flex-col gap-4">
                        {breakdown.lines.map((line) => (
                            <ScoreLineView key={line.key} line={line} />
                        ))}
                    </div>
                )}
            </CardContent>
        </Card>
    );
}

function ScoreLineView({ line }: { line: ScoreLine }) {
    const percent = line.max > 0 ? (line.points / line.max) * 100 : 0;

    return (
        <div className="flex flex-col gap-1.5 text-sm">
            <div className="flex items-center justify-between">
                <span className="font-medium">
                    {LINE_LABELS[line.key] ?? humanise(line.key)}
                </span>
                <span className="tabular-nums">
                    {line.points.toFixed(1)} / {line.max.toFixed(1)}
                </span>
            </div>
            <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                <div
                    className="h-full rounded-full bg-primary"
                    style={{ width: `${percent}%` }}
                />
            </div>
            <ul className="flex flex-col gap-0.5 text-xs text-muted-foreground">
                {Object.entries(line.items).map(([item, points]) => (
                    <li key={item} className="flex justify-between gap-2">
                        <span className={cn(points > 0 && 'text-foreground')}>
                            {ITEM_LABELS[item] ?? humanise(item)}
                            {item === 'newest_ad' &&
                                line.daysOld !== null &&
                                ` (${line.daysOld} days old)`}
                        </span>
                        <span className="tabular-nums">
                            {points > 0 ? `+${points.toFixed(1)}` : '0'}
                        </span>
                    </li>
                ))}
            </ul>
        </div>
    );
}

function EvidenceCard({
    laravel,
    offshore,
    signals,
}: {
    laravel: Evidence[];
    offshore: Evidence[];
    signals: CompanyDetailProps['signals'];
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Evidence</CardTitle>
            </CardHeader>
            <CardContent className="grid gap-6 text-sm md:grid-cols-2">
                <EvidenceList title="Laravel signals" items={laravel} />
                <EvidenceList title="Offshore signals" items={offshore} />
                <div className="md:col-span-2">
                    <h3 className="mb-2 font-medium">Job ads</h3>
                    {signals.length === 0 ? (
                        <p className="text-muted-foreground">None.</p>
                    ) : (
                        <ul className="flex flex-col gap-2">
                            {signals.map((signal) => (
                                <li key={signal.id} className="flex flex-col">
                                    {signal.url ? (
                                        <ExternalAnchor href={signal.url}>
                                            {signal.title}
                                        </ExternalAnchor>
                                    ) : (
                                        <span>{signal.title}</span>
                                    )}
                                    <span className="text-xs text-muted-foreground">
                                        {humanise(signal.source)} ·{' '}
                                        {signal.employerName}
                                        {signal.location &&
                                            ` · ${signal.location}`}
                                        {signal.postedAt &&
                                            ` · posted ${formatDate(signal.postedAt)}`}
                                    </span>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            </CardContent>
        </Card>
    );
}

function EvidenceList({ title, items }: { title: string; items: Evidence[] }) {
    return (
        <div>
            <h3 className="mb-2 font-medium">{title}</h3>
            {items.length === 0 ? (
                <p className="text-muted-foreground">None found.</p>
            ) : (
                <ul className="flex flex-col gap-2">
                    {items.map((item, i) => (
                        <li key={i} className="flex flex-col">
                            <span className="flex items-center gap-2">
                                {humanise(item.type)}
                                {item.weight && (
                                    <Badge
                                        variant={
                                            item.weight === 'strong'
                                                ? 'default'
                                                : 'secondary'
                                        }
                                        className="h-5 text-[10px]"
                                    >
                                        {item.weight}
                                    </Badge>
                                )}
                            </span>
                            <span className="text-xs text-muted-foreground">
                                {item.detail}
                                {item.url && (
                                    <>
                                        {' · '}
                                        <ExternalAnchor href={item.url}>
                                            source
                                        </ExternalAnchor>
                                    </>
                                )}
                            </span>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function NotesCard({
    companyId,
    notes,
}: {
    companyId: number;
    notes: string | null;
}) {
    const [value, setValue] = useState(notes ?? '');
    const [saving, setSaving] = useState(false);
    const dirty = value !== (notes ?? '');

    return (
        <Card>
            <CardHeader>
                <CardTitle>Notes</CardTitle>
            </CardHeader>
            <CardContent className="flex flex-col gap-2">
                <textarea
                    value={value}
                    onChange={(e) => setValue(e.target.value)}
                    rows={6}
                    maxLength={10000}
                    aria-label="Notes"
                    placeholder="Anything worth remembering about this lead"
                    className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                />
                <div>
                    <Button
                        size="sm"
                        disabled={!dirty || saving}
                        onClick={() =>
                            router.patch(
                                update.url(companyId),
                                { notes: value },
                                {
                                    preserveScroll: true,
                                    onStart: () => setSaving(true),
                                    onFinish: () => setSaving(false),
                                },
                            )
                        }
                    >
                        Save notes
                    </Button>
                </div>
            </CardContent>
        </Card>
    );
}

const OUTCOME_CLASSES: Record<string, string> = {
    ok: 'bg-emerald-500',
    skipped: 'bg-muted-foreground',
    failed: 'bg-red-500',
    rejected: 'bg-amber-500',
};

function TimelineCard({ company, events, options }: CompanyDetailProps) {
    const lastFailure = [...events]
        .reverse()
        .find((e) => e.outcome === 'failed' || e.outcome === 'rejected');

    const runRetry = (step?: string) =>
        router.post(
            retry.url(company.id),
            { step: step ?? null },
            { preserveScroll: true },
        );

    return (
        <Card>
            <CardHeader className="flex flex-row flex-wrap items-center justify-between gap-2">
                <div>
                    <CardTitle>Pipeline timeline</CardTitle>
                    <CardDescription>
                        Current step: {humanise(company.pipelineStep)}
                    </CardDescription>
                </div>
                {company.canRetry && (
                    <Select onValueChange={(step) => runRetry(step)}>
                        <SelectTrigger
                            className="w-48"
                            aria-label="Re-run from step"
                        >
                            <SelectValue placeholder="Re-run from step…" />
                        </SelectTrigger>
                        <SelectContent>
                            {options.step.map((step) => (
                                <SelectItem key={step.value} value={step.value}>
                                    {step.label}
                                </SelectItem>
                            ))}
                        </SelectContent>
                    </Select>
                )}
            </CardHeader>
            <CardContent>
                {events.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No events yet.
                    </p>
                ) : (
                    <ol className="flex flex-col">
                        {events.map((event) => (
                            <li
                                key={event.id}
                                className="flex gap-3 border-l py-2 pl-4 first:pt-0 last:pb-0"
                            >
                                <span
                                    className={cn(
                                        'mt-1.5 -ml-[21px] size-2.5 shrink-0 rounded-full',
                                        OUTCOME_CLASSES[event.outcome],
                                    )}
                                />
                                <div className="flex min-w-0 flex-1 flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                    <div className="min-w-0 text-sm">
                                        <span className="font-medium">
                                            {humanise(event.step)}
                                        </span>{' '}
                                        <span className="text-muted-foreground">
                                            · {event.outcome}
                                        </span>
                                        {event.message && (
                                            <div className="break-words text-muted-foreground">
                                                {event.message}
                                            </div>
                                        )}
                                    </div>
                                    <div className="flex items-center gap-2">
                                        {company.canRetry &&
                                            event.id === lastFailure?.id && (
                                                <Button
                                                    size="sm"
                                                    variant="outline"
                                                    className="h-7"
                                                    onClick={() =>
                                                        runRetry(event.step)
                                                    }
                                                >
                                                    <RotateCcw className="size-3.5" />
                                                    Retry
                                                </Button>
                                            )}
                                        {event.createdAt && (
                                            <span className="text-xs whitespace-nowrap text-muted-foreground">
                                                {formatDateTime(
                                                    event.createdAt,
                                                )}
                                            </span>
                                        )}
                                    </div>
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </CardContent>
        </Card>
    );
}

function Field({
    label,
    children,
    wide,
}: {
    label: string;
    children: React.ReactNode;
    wide?: boolean;
}) {
    const empty =
        children === null ||
        children === undefined ||
        children === '' ||
        children === false;

    return (
        <div className={cn(wide && 'sm:col-span-2')}>
            <dt className="text-xs text-muted-foreground">{label}</dt>
            <dd className="mt-0.5">
                {empty ? (
                    <span className="text-muted-foreground">—</span>
                ) : (
                    children
                )}
            </dd>
        </div>
    );
}

function ExternalAnchor({
    href,
    children,
}: {
    href: string;
    children: React.ReactNode;
}) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noreferrer noopener"
            className="inline-flex items-center gap-1 break-all hover:underline"
        >
            {children}
            <ExternalLink className="size-3 shrink-0" />
        </a>
    );
}

function SourceLink({ href, label }: { href: string; label: string }) {
    return (
        <a
            href={href}
            target="_blank"
            rel="noreferrer noopener"
            className="text-xs text-muted-foreground hover:underline"
            title={href}
        >
            {label}
        </a>
    );
}

function formatDate(iso: string): string {
    const date =
        iso.length === 10 ? new Date(`${iso}T12:00:00`) : new Date(iso);

    return date.toLocaleDateString('en-GB', {
        timeZone: 'Europe/London',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatDateTime(iso: string): string {
    return new Date(iso).toLocaleString('en-GB', {
        timeZone: 'Europe/London',
        day: 'numeric',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}
