import { Head, Link, router } from '@inertiajs/react';
import {
    ArrowDown,
    ArrowUp,
    ChevronDown,
    ExternalLink,
    Linkedin,
    Search,
    X,
} from 'lucide-react';
import { useEffect, useRef, useState } from 'react';
import {
    humanise,
    ScoreBadge,
    StatusBadge,
    VerificationBadge,
} from '@/components/lead-badges';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    DropdownMenu,
    DropdownMenuCheckboxItem,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuSub,
    DropdownMenuSubContent,
    DropdownMenuSubTrigger,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Input } from '@/components/ui/input';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { Slider } from '@/components/ui/slider';
import {
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { bulk, index } from '@/routes/companies';
import type {
    CompaniesIndexProps,
    CompanyFilters,
    CompanyRow,
    Option,
} from '@/types/companies';

const ANY = '__any';

/** Score quick chips (docs/06 bands). Near misses are below-threshold rejects (docs/06 §qualify). */
const CHIPS: {
    key: string;
    label: string;
    patch: Partial<CompanyFilters>;
}[] = [
    {
        key: 'excellent',
        label: 'Excellent',
        patch: {
            score_min: 8,
            score_max: 10,
            status: [],
            reject_reason: null,
        },
    },
    {
        key: 'good',
        label: 'Good',
        patch: {
            score_min: 6,
            score_max: 7.9,
            status: [],
            reject_reason: null,
        },
    },
    {
        key: 'near_miss',
        label: 'Near miss',
        patch: {
            score_min: 4,
            score_max: 5.9,
            status: ['rejected'],
            reject_reason: 'below_threshold',
        },
    },
];

export default function CompaniesIndex({
    filters,
    companies,
    options,
}: CompaniesIndexProps) {
    const [selected, setSelected] = useState<number[]>([]);

    const visit = (patch: Partial<CompanyFilters>) => {
        const next = { ...filters, ...patch };
        setSelected([]);
        router.get(index.url(), toQuery(next), {
            preserveState: true,
            preserveScroll: true,
            replace: true,
        });
    };

    const pageIds = companies.data.map((c) => c.id);
    const allSelected =
        pageIds.length > 0 && pageIds.every((id) => selected.includes(id));

    return (
        <>
            <Head title="Leads" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <FilterBar filters={filters} options={options} visit={visit} />

                {selected.length > 0 && (
                    <BulkBar
                        ids={selected}
                        steps={options.step}
                        onDone={() => setSelected([])}
                    />
                )}

                <div className="overflow-x-auto rounded-xl border">
                    <Table>
                        <TableHeader>
                            <TableRow>
                                <TableHead className="w-8">
                                    <Checkbox
                                        aria-label="Select all on this page"
                                        checked={allSelected}
                                        onCheckedChange={(checked) =>
                                            setSelected(
                                                checked === true ? pageIds : [],
                                            )
                                        }
                                    />
                                </TableHead>
                                <SortHead
                                    label="Score"
                                    column="score"
                                    filters={filters}
                                    visit={visit}
                                />
                                <SortHead
                                    label="Company"
                                    column="name"
                                    filters={filters}
                                    visit={visit}
                                />
                                <TableHead>Type</TableHead>
                                <TableHead>Location</TableHead>
                                <TableHead>Reg. no</TableHead>
                                <TableHead>Founder 1</TableHead>
                                <TableHead>Founder 2</TableHead>
                                <TableHead>Contact email</TableHead>
                                <TableHead>Signal</TableHead>
                                <TableHead>Status</TableHead>
                                <TableHead>Outreach</TableHead>
                                <SortHead
                                    label="Qualified"
                                    column="qualified_at"
                                    filters={filters}
                                    visit={visit}
                                />
                            </TableRow>
                        </TableHeader>
                        <TableBody>
                            {companies.data.length === 0 && (
                                <TableRow>
                                    <TableCell
                                        colSpan={13}
                                        className="h-24 text-center text-muted-foreground"
                                    >
                                        No leads match these filters.
                                    </TableCell>
                                </TableRow>
                            )}
                            {companies.data.map((company) => (
                                <CompanyTableRow
                                    key={company.id}
                                    company={company}
                                    selected={selected.includes(company.id)}
                                    onSelect={(checked) =>
                                        setSelected((ids) =>
                                            checked
                                                ? [...ids, company.id]
                                                : ids.filter(
                                                      (id) => id !== company.id,
                                                  ),
                                        )
                                    }
                                />
                            ))}
                        </TableBody>
                    </Table>
                </div>

                <Pagination
                    companies={companies}
                    perPage={filters.per_page}
                    visit={visit}
                />
            </div>
        </>
    );
}

CompaniesIndex.layout = {
    breadcrumbs: [{ title: 'Leads', href: index() }],
};

type QueryValue = string | number | string[];

function toQuery(filters: CompanyFilters): Record<string, QueryValue> {
    const query: Record<string, QueryValue> = {};

    for (const [key, value] of Object.entries(filters)) {
        if (
            value === null ||
            value === false ||
            value === '' ||
            (Array.isArray(value) && value.length === 0)
        ) {
            continue;
        }

        query[key] = value === true ? 1 : (value as QueryValue);
    }

    // Defaults stay out of the URL.
    if (query.sort === 'score') {
        delete query.sort;
        if (query.dir === 'desc') {
            delete query.dir;
        }
    }
    if (query.sort === 'name' && query.dir === 'asc') {
        delete query.dir;
    }
    if (query.per_page === 25) {
        delete query.per_page;
    }

    return query;
}

type Visit = (patch: Partial<CompanyFilters>) => void;

function FilterBar({
    filters,
    options,
    visit,
}: {
    filters: CompanyFilters;
    options: CompaniesIndexProps['options'];
    visit: Visit;
}) {
    const [q, setQ] = useState(filters.q ?? '');
    const [score, setScore] = useState<[number, number]>([
        filters.score_min ?? 0,
        filters.score_max ?? 10,
    ]);
    const firstRender = useRef(true);

    useEffect(() => {
        if (firstRender.current) {
            firstRender.current = false;

            return;
        }

        const timer = setTimeout(() => {
            if (q.trim() !== (filters.q ?? '')) {
                visit({ q: q.trim() === '' ? null : q.trim() });
            }
        }, 350);

        return () => clearTimeout(timer);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [q]);

    useEffect(() => {
        setScore([filters.score_min ?? 0, filters.score_max ?? 10]);
    }, [filters.score_min, filters.score_max]);

    const activeChip = CHIPS.find(
        (chip) =>
            chip.patch.score_min === filters.score_min &&
            chip.patch.score_max === filters.score_max &&
            (chip.patch.reject_reason ?? null) === filters.reject_reason,
    )?.key;

    const hasFilters =
        filters.status.length > 0 ||
        filters.outreach.length > 0 ||
        filters.type.length > 0 ||
        filters.score_min !== null ||
        filters.score_max !== null ||
        filters.country !== null ||
        filters.source !== null ||
        filters.qualified_from !== null ||
        filters.qualified_to !== null ||
        filters.verified ||
        filters.linkedin ||
        filters.reject_reason !== null ||
        filters.q !== null;

    return (
        <div className="flex flex-col gap-3">
            <div className="flex flex-wrap items-center gap-2">
                <div className="relative w-full sm:w-72">
                    <Search className="pointer-events-none absolute top-2.5 left-2.5 size-4 text-muted-foreground" />
                    <Input
                        value={q}
                        onChange={(e) => setQ(e.target.value)}
                        placeholder="Search name, domain, founder, email"
                        aria-label="Search"
                        className="pl-8"
                    />
                </div>

                <MultiSelect
                    label="Status"
                    options={options.status}
                    value={filters.status}
                    onChange={(status) =>
                        visit({
                            status,
                            reject_reason: status.includes('rejected')
                                ? filters.reject_reason
                                : null,
                        })
                    }
                />
                <MultiSelect
                    label="Outreach"
                    options={options.outreach}
                    value={filters.outreach}
                    onChange={(outreach) => visit({ outreach })}
                />
                <MultiSelect
                    label="Type"
                    options={options.type}
                    value={filters.type}
                    onChange={(type) => visit({ type })}
                />

                <SingleSelect
                    label="Country"
                    value={filters.country}
                    options={options.country.map((c) => ({
                        value: c,
                        label: c,
                    }))}
                    onChange={(country) => visit({ country })}
                />
                <SingleSelect
                    label="Source"
                    value={filters.source}
                    options={options.source}
                    onChange={(source) => visit({ source })}
                />
                {filters.status.includes('rejected') && (
                    <SingleSelect
                        label="Reject reason"
                        value={filters.reject_reason}
                        options={options.rejectReason}
                        onChange={(reject_reason) => visit({ reject_reason })}
                    />
                )}

                {hasFilters && (
                    <Button
                        variant="ghost"
                        size="sm"
                        onClick={() => {
                            setQ('');
                            visit({
                                status: [],
                                outreach: [],
                                type: [],
                                score_min: null,
                                score_max: null,
                                country: null,
                                source: null,
                                qualified_from: null,
                                qualified_to: null,
                                verified: false,
                                linkedin: false,
                                reject_reason: null,
                                q: null,
                            });
                        }}
                    >
                        <X className="size-4" /> Clear
                    </Button>
                )}
            </div>

            <div className="flex flex-wrap items-center gap-x-5 gap-y-3">
                <div className="flex items-center gap-3">
                    <span className="text-sm text-muted-foreground">Score</span>
                    <Slider
                        className="w-40"
                        min={0}
                        max={10}
                        step={0.5}
                        value={score}
                        onValueChange={(value) =>
                            setScore([value[0], value[1]])
                        }
                        onValueCommit={(value) =>
                            visit({
                                score_min: value[0] > 0 ? value[0] : null,
                                score_max: value[1] < 10 ? value[1] : null,
                            })
                        }
                        aria-label="Score range"
                    />
                    <span className="w-16 text-sm tabular-nums">
                        {score[0].toFixed(1)}–{score[1].toFixed(1)}
                    </span>
                </div>

                <div className="flex gap-1">
                    {CHIPS.map((chip) => (
                        <Button
                            key={chip.key}
                            size="sm"
                            variant={
                                activeChip === chip.key ? 'default' : 'outline'
                            }
                            className="h-7 rounded-full"
                            onClick={() =>
                                activeChip === chip.key
                                    ? visit({
                                          score_min: null,
                                          score_max: null,
                                          reject_reason: null,
                                          status: [],
                                      })
                                    : visit(chip.patch)
                            }
                        >
                            {chip.label}
                        </Button>
                    ))}
                </div>

                <div className="flex items-center gap-2">
                    <span className="text-sm text-muted-foreground">
                        Qualified
                    </span>
                    <Input
                        type="date"
                        aria-label="Qualified from"
                        className="h-8 w-36"
                        value={filters.qualified_from ?? ''}
                        onChange={(e) =>
                            visit({ qualified_from: e.target.value || null })
                        }
                    />
                    <span className="text-sm text-muted-foreground">to</span>
                    <Input
                        type="date"
                        aria-label="Qualified to"
                        className="h-8 w-36"
                        value={filters.qualified_to ?? ''}
                        onChange={(e) =>
                            visit({ qualified_to: e.target.value || null })
                        }
                    />
                </div>

                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={filters.verified}
                        onCheckedChange={(checked) =>
                            visit({ verified: checked === true })
                        }
                    />
                    Verified founder email
                </label>
                <label className="flex items-center gap-2 text-sm">
                    <Checkbox
                        checked={filters.linkedin}
                        onCheckedChange={(checked) =>
                            visit({ linkedin: checked === true })
                        }
                    />
                    Has LinkedIn
                </label>
            </div>
        </div>
    );
}

function MultiSelect({
    label,
    options,
    value,
    onChange,
}: {
    label: string;
    options: Option[];
    value: string[];
    onChange: (value: string[]) => void;
}) {
    return (
        <DropdownMenu>
            <DropdownMenuTrigger asChild>
                <Button variant="outline" size="sm" className="h-9">
                    {label}
                    {value.length > 0 && (
                        <span className="rounded bg-muted px-1.5 text-xs tabular-nums">
                            {value.length}
                        </span>
                    )}
                    <ChevronDown className="size-4 opacity-60" />
                </Button>
            </DropdownMenuTrigger>
            <DropdownMenuContent align="start">
                {options.map((option) => (
                    <DropdownMenuCheckboxItem
                        key={option.value}
                        checked={value.includes(option.value)}
                        onSelect={(e) => e.preventDefault()}
                        onCheckedChange={(checked) =>
                            onChange(
                                checked
                                    ? [...value, option.value]
                                    : value.filter((v) => v !== option.value),
                            )
                        }
                    >
                        {option.label}
                    </DropdownMenuCheckboxItem>
                ))}
            </DropdownMenuContent>
        </DropdownMenu>
    );
}

function SingleSelect({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: string | null;
    options: Option[];
    onChange: (value: string | null) => void;
}) {
    return (
        <Select
            value={value ?? ANY}
            onValueChange={(v) => onChange(v === ANY ? null : v)}
        >
            <SelectTrigger className="h-9 w-auto min-w-28" aria-label={label}>
                <SelectValue placeholder={label} />
            </SelectTrigger>
            <SelectContent>
                <SelectItem value={ANY}>Any {label.toLowerCase()}</SelectItem>
                {options.map((option) => (
                    <SelectItem key={option.value} value={option.value}>
                        {option.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

function SortHead({
    label,
    column,
    filters,
    visit,
}: {
    label: string;
    column: CompanyFilters['sort'];
    filters: CompanyFilters;
    visit: Visit;
}) {
    const active = filters.sort === column;
    const Icon = filters.dir === 'asc' ? ArrowUp : ArrowDown;

    return (
        <TableHead>
            <button
                type="button"
                className="inline-flex items-center gap-1 hover:text-foreground"
                onClick={() =>
                    visit({
                        sort: column,
                        dir: active
                            ? filters.dir === 'asc'
                                ? 'desc'
                                : 'asc'
                            : column === 'name'
                              ? 'asc'
                              : 'desc',
                    })
                }
            >
                {label}
                {active && <Icon className="size-3.5" />}
            </button>
        </TableHead>
    );
}

function CompanyTableRow({
    company,
    selected,
    onSelect,
}: {
    company: CompanyRow;
    selected: boolean;
    onSelect: (checked: boolean) => void;
}) {
    const founder = (position: number) =>
        company.founders.find((f) => f.position === position);

    return (
        <TableRow data-state={selected ? 'selected' : undefined}>
            <TableCell>
                <Checkbox
                    aria-label={`Select ${company.name}`}
                    checked={selected}
                    onCheckedChange={(checked) => onSelect(checked === true)}
                />
            </TableCell>
            <TableCell>
                <ScoreBadge score={company.score} />
            </TableCell>
            <TableCell className="max-w-56">
                <div className="truncate font-medium">{company.name}</div>
                {company.website && (
                    <a
                        href={company.website}
                        target="_blank"
                        rel="noreferrer noopener"
                        className="inline-flex items-center gap-1 text-xs text-muted-foreground hover:underline"
                    >
                        {company.domain}
                        <ExternalLink className="size-3" />
                    </a>
                )}
            </TableCell>
            <TableCell>{humanise(company.type)}</TableCell>
            <TableCell className="max-w-36 truncate">
                {company.location ?? '—'}
            </TableCell>
            <TableCell className="font-mono text-xs">
                {company.registrationNumber ?? '—'}
            </TableCell>
            <FounderCell founder={founder(1)} />
            <FounderCell founder={founder(2)} />
            <TableCell className="max-w-48">
                {company.contactEmail ? (
                    <div className="flex flex-col">
                        <span className="truncate text-sm">
                            {company.contactEmail.email}
                        </span>
                        <VerificationBadge
                            status={company.contactEmail.status}
                        />
                    </div>
                ) : (
                    '—'
                )}
            </TableCell>
            <TableCell className="max-w-48">
                {company.signal ? (
                    <div className="flex flex-col">
                        {company.signal.url ? (
                            <a
                                href={company.signal.url}
                                target="_blank"
                                rel="noreferrer noopener"
                                className="truncate text-sm hover:underline"
                            >
                                {company.signal.title}
                            </a>
                        ) : (
                            <span className="truncate text-sm">
                                {company.signal.title}
                            </span>
                        )}
                        <span className="text-xs text-muted-foreground">
                            {humanise(company.signal.source)}
                            {company.signal.postedAt &&
                                ` · ${age(company.signal.postedAt)}`}
                        </span>
                    </div>
                ) : (
                    '—'
                )}
            </TableCell>
            <TableCell>
                <StatusBadge status={company.status} />
                {company.rejectReason && (
                    <div className="mt-0.5 text-xs text-muted-foreground">
                        {humanise(company.rejectReason)}
                    </div>
                )}
            </TableCell>
            <TableCell className="text-sm">
                {humanise(company.outreachStatus)}
            </TableCell>
            <TableCell className="text-sm whitespace-nowrap">
                {company.qualifiedAt ? formatDate(company.qualifiedAt) : '—'}
            </TableCell>
        </TableRow>
    );
}

function FounderCell({
    founder,
}: {
    founder: CompanyRow['founders'][number] | undefined;
}) {
    if (!founder) {
        return <TableCell className="text-muted-foreground">—</TableCell>;
    }

    return (
        <TableCell className="max-w-48">
            <div className="flex items-center gap-1">
                <span className="truncate text-sm">{founder.name}</span>
                {founder.linkedinUrl && (
                    <a
                        href={founder.linkedinUrl}
                        target="_blank"
                        rel="noreferrer noopener"
                        aria-label={`${founder.name} on LinkedIn`}
                        className="text-muted-foreground hover:text-foreground"
                    >
                        <Linkedin className="size-3.5" />
                    </a>
                )}
            </div>
            {founder.email && (
                <div className="flex flex-col">
                    <span className="truncate text-xs text-muted-foreground">
                        {founder.email}
                    </span>
                    <VerificationBadge status={founder.emailStatus} />
                </div>
            )}
        </TableCell>
    );
}

const BULK_LABELS: Record<string, string> = {
    contacted: 'Mark contacted',
    not_interested: 'Mark not interested',
    suppress_domain: 'Suppress domain',
};

function BulkBar({
    ids,
    steps,
    onDone,
}: {
    ids: number[];
    steps: Option[];
    onDone: () => void;
}) {
    const [processing, setProcessing] = useState(false);

    const run = (action: string, step?: string) => {
        if (
            (action === 'not_interested' || action === 'suppress_domain') &&
            !window.confirm(
                `${BULK_LABELS[action]} for ${ids.length} lead(s)? Their domains go on the do-not-contact list.`,
            )
        ) {
            return;
        }

        router.post(
            bulk.url(),
            { action, ids, step: step ?? null },
            {
                preserveScroll: true,
                onStart: () => setProcessing(true),
                onFinish: () => setProcessing(false),
                onSuccess: onDone,
            },
        );
    };

    return (
        <div className="flex flex-wrap items-center gap-2 rounded-lg border bg-muted/50 px-3 py-2">
            <span className="text-sm font-medium">{ids.length} selected</span>
            {Object.entries(BULK_LABELS).map(([action, label]) => (
                <Button
                    key={action}
                    size="sm"
                    variant="outline"
                    disabled={processing}
                    onClick={() => run(action)}
                >
                    {label}
                </Button>
            ))}
            <DropdownMenu>
                <DropdownMenuTrigger asChild>
                    <Button size="sm" variant="outline" disabled={processing}>
                        Re-run from step
                        <ChevronDown className="size-4 opacity-60" />
                    </Button>
                </DropdownMenuTrigger>
                <DropdownMenuContent align="start">
                    <DropdownMenuLabel className="text-xs font-normal text-muted-foreground">
                        Rejected and errored leads only
                    </DropdownMenuLabel>
                    <DropdownMenuItem onSelect={() => run('retry')}>
                        Where it stopped
                    </DropdownMenuItem>
                    <DropdownMenuSeparator />
                    <DropdownMenuSub>
                        <DropdownMenuSubTrigger>
                            From step…
                        </DropdownMenuSubTrigger>
                        <DropdownMenuSubContent>
                            {steps.map((step) => (
                                <DropdownMenuItem
                                    key={step.value}
                                    onSelect={() => run('retry', step.value)}
                                >
                                    {step.label}
                                </DropdownMenuItem>
                            ))}
                        </DropdownMenuSubContent>
                    </DropdownMenuSub>
                </DropdownMenuContent>
            </DropdownMenu>
            <Button size="sm" variant="ghost" onClick={onDone}>
                Clear selection
            </Button>
        </div>
    );
}

function Pagination({
    companies,
    perPage,
    visit,
}: {
    companies: CompaniesIndexProps['companies'];
    perPage: number;
    visit: Visit;
}) {
    return (
        <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
            <span className="text-muted-foreground">
                {companies.total === 0
                    ? 'No leads'
                    : `${companies.from}–${companies.to} of ${companies.total}`}
            </span>
            <div className="flex items-center gap-2">
                <Select
                    value={String(perPage)}
                    onValueChange={(v) => visit({ per_page: Number(v) })}
                >
                    <SelectTrigger className="h-8 w-32" aria-label="Per page">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        {[25, 50, 100].map((n) => (
                            <SelectItem key={n} value={String(n)}>
                                {n} / page
                            </SelectItem>
                        ))}
                    </SelectContent>
                </Select>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={!companies.prevPageUrl}
                    asChild={!!companies.prevPageUrl}
                >
                    {companies.prevPageUrl ? (
                        <Link
                            href={companies.prevPageUrl}
                            preserveScroll
                            preserveState
                        >
                            Previous
                        </Link>
                    ) : (
                        <span>Previous</span>
                    )}
                </Button>
                <span className="text-muted-foreground tabular-nums">
                    {companies.currentPage} / {companies.lastPage}
                </span>
                <Button
                    size="sm"
                    variant="outline"
                    disabled={!companies.nextPageUrl}
                    asChild={!!companies.nextPageUrl}
                >
                    {companies.nextPageUrl ? (
                        <Link
                            href={companies.nextPageUrl}
                            preserveScroll
                            preserveState
                        >
                            Next
                        </Link>
                    ) : (
                        <span>Next</span>
                    )}
                </Button>
            </div>
        </div>
    );
}

function formatDate(iso: string): string {
    return new Date(iso).toLocaleDateString('en-GB', {
        timeZone: 'Europe/London',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function age(iso: string): string {
    const days = Math.floor((Date.now() - new Date(iso).getTime()) / 86400000);

    if (days <= 0) {
        return 'today';
    }

    return days === 1 ? '1 day ago' : `${days} days ago`;
}
