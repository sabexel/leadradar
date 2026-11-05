import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import {
    Bar,
    BarChart,
    CartesianGrid,
    Cell,
    LabelList,
    Pie,
    PieChart,
    ReferenceLine,
    XAxis,
    YAxis,
} from 'recharts';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import type { ChartConfig } from '@/components/ui/chart';
import {
    ChartContainer,
    ChartLegend,
    ChartLegendContent,
    ChartTooltip,
    ChartTooltipContent,
} from '@/components/ui/chart';
import { Input } from '@/components/ui/input';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { dashboard } from '@/routes';
import type {
    DashboardApiUsage,
    DashboardProps,
    DashboardRun,
    RangePreset,
} from '@/types/dashboard';

const RANGE_LABELS: Record<RangePreset, string> = {
    today: 'Today',
    '7d': '7 days',
    '30d': '30 days',
    custom: 'Custom',
};

const SOURCE_COLOURS = [
    'var(--chart-1)',
    'var(--chart-2)',
    'var(--chart-3)',
    'var(--chart-4)',
    'var(--chart-5)',
];

const number = new Intl.NumberFormat('en-GB');

export default function Dashboard(props: DashboardProps) {
    const { stats } = props;

    return (
        <>
            <Head title="Dashboard" />
            <div className="flex h-full flex-1 flex-col gap-4 overflow-x-auto p-4">
                <RangePicker range={props.range} />

                <div className="grid grid-cols-2 gap-4 md:grid-cols-3 xl:grid-cols-5">
                    <StatCard
                        label="Scraped"
                        value={stats.scraped}
                        hint="Signals ingested"
                    />
                    <StatCard
                        label="Companies discovered"
                        value={stats.discovered}
                    />
                    <StatCard
                        label="Qualified"
                        value={stats.qualified}
                        tone="good"
                    />
                    <StatCard label="Rejected" value={stats.rejected} />
                    <StatCard label="In progress" value={stats.inProgress} />
                    <StatCard
                        label="Errors"
                        value={stats.errors}
                        tone={stats.errors > 0 ? 'bad' : undefined}
                    />
                    <StatCard
                        label="Average score"
                        value={
                            stats.averageScore === null
                                ? '—'
                                : stats.averageScore.toFixed(1)
                        }
                        hint="Qualified leads"
                    />
                    <StatCard
                        label="Verified founder emails"
                        value={stats.verifiedFounderEmails}
                    />
                    <StatCard label="Contacted" value={stats.contacted} />
                    <StatCard
                        label="Replied"
                        value={stats.replied}
                        hint="Of those contacted"
                    />
                </div>

                <div className="grid gap-4 lg:grid-cols-2">
                    <QualifiedPerDayChart data={props.qualifiedPerDay} />
                    <FunnelChart data={props.funnel} />
                    <RejectReasonsChart data={props.rejectReasons} />
                    <ScoreDistributionChart data={props.scoreDistribution} />
                </div>

                <div className="grid gap-4 lg:grid-cols-3">
                    <SourcesChart data={props.sources} />
                    <RunsPanel runs={props.runs} />
                    <ApiUsagePanel
                        usage={props.apiUsage}
                        credits={props.credits}
                    />
                </div>
            </div>
        </>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: dashboard(),
        },
    ],
};

function RangePicker({ range }: { range: DashboardProps['range'] }) {
    const [from, setFrom] = useState(range.from);
    const [to, setTo] = useState(range.to);
    const [showCustom, setShowCustom] = useState(range.preset === 'custom');

    const visit = (query: Record<string, string>) =>
        router.get(dashboard.url({ query }), {}, { preserveScroll: true });

    return (
        <div className="flex flex-wrap items-center gap-3">
            <ToggleGroup
                type="single"
                variant="outline"
                value={showCustom ? 'custom' : range.preset}
                onValueChange={(value: string) => {
                    if (value === '') {
                        return;
                    }

                    if (value === 'custom') {
                        setShowCustom(true);

                        return;
                    }

                    setShowCustom(false);
                    visit({ range: value });
                }}
                aria-label="Date range"
            >
                {(Object.keys(RANGE_LABELS) as RangePreset[]).map((preset) => (
                    <ToggleGroupItem
                        key={preset}
                        value={preset}
                        className="px-3"
                    >
                        {RANGE_LABELS[preset]}
                    </ToggleGroupItem>
                ))}
            </ToggleGroup>

            {showCustom && (
                <form
                    className="flex flex-wrap items-center gap-2"
                    onSubmit={(event) => {
                        event.preventDefault();
                        visit({ range: 'custom', from, to });
                    }}
                >
                    <Input
                        type="date"
                        aria-label="From"
                        value={from}
                        onChange={(e) => setFrom(e.target.value)}
                        className="w-40"
                    />
                    <span className="text-sm text-muted-foreground">to</span>
                    <Input
                        type="date"
                        aria-label="To"
                        value={to}
                        onChange={(e) => setTo(e.target.value)}
                        className="w-40"
                    />
                    <Button type="submit" size="sm">
                        Apply
                    </Button>
                </form>
            )}

            <span className="text-sm text-muted-foreground">
                {range.from === range.to
                    ? formatDate(range.from)
                    : `${formatDate(range.from)} – ${formatDate(range.to)}`}
            </span>
        </div>
    );
}

function StatCard({
    label,
    value,
    hint,
    tone,
}: {
    label: string;
    value: number | string;
    hint?: string;
    tone?: 'good' | 'bad';
}) {
    const colour =
        tone === 'good'
            ? 'text-emerald-600 dark:text-emerald-400'
            : tone === 'bad'
              ? 'text-red-600 dark:text-red-400'
              : '';

    return (
        <Card className="gap-1 py-4">
            <CardHeader className="px-4">
                <CardDescription>{label}</CardDescription>
            </CardHeader>
            <CardContent className="px-4">
                <div
                    className={`text-2xl font-semibold tabular-nums ${colour}`}
                >
                    {typeof value === 'number' ? number.format(value) : value}
                </div>
                {hint && (
                    <p className="text-xs text-muted-foreground">{hint}</p>
                )}
            </CardContent>
        </Card>
    );
}

function ChartCard({
    title,
    description,
    empty,
    children,
}: {
    title: string;
    description?: string;
    empty?: boolean;
    children: React.ReactNode;
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>{title}</CardTitle>
                {description && (
                    <CardDescription>{description}</CardDescription>
                )}
            </CardHeader>
            <CardContent>
                {empty ? (
                    <div className="flex h-[240px] items-center justify-center text-sm text-muted-foreground">
                        Nothing in this range yet.
                    </div>
                ) : (
                    children
                )}
            </CardContent>
        </Card>
    );
}

const qualifiedConfig = {
    qualified: { label: 'Qualified', color: 'var(--chart-2)' },
} satisfies ChartConfig;

function QualifiedPerDayChart({
    data,
}: {
    data: DashboardProps['qualifiedPerDay'];
}) {
    return (
        <ChartCard
            title="Qualified per day"
            description={`Last 30 days · target ${data.target}/day`}
        >
            <ChartContainer
                config={qualifiedConfig}
                className="h-[240px] w-full"
            >
                <BarChart data={data.days} margin={{ top: 12, right: 12 }}>
                    <CartesianGrid vertical={false} />
                    <XAxis
                        dataKey="date"
                        tickLine={false}
                        axisLine={false}
                        minTickGap={24}
                        tickFormatter={(value: string) => formatDay(value)}
                    />
                    <YAxis
                        allowDecimals={false}
                        tickLine={false}
                        axisLine={false}
                        width={32}
                        domain={[
                            0,
                            (max: number) => Math.max(max, data.target),
                        ]}
                    />
                    <ChartTooltip
                        content={
                            <ChartTooltipContent
                                labelFormatter={(value) =>
                                    formatDate(String(value))
                                }
                            />
                        }
                    />
                    <ReferenceLine
                        y={data.target}
                        stroke="var(--chart-1)"
                        strokeDasharray="4 4"
                        label={{
                            value: 'Target',
                            position: 'insideTopRight',
                            fill: 'var(--chart-1)',
                            fontSize: 12,
                        }}
                    />
                    <Bar
                        dataKey="qualified"
                        fill="var(--color-qualified)"
                        radius={[3, 3, 0, 0]}
                    />
                </BarChart>
            </ChartContainer>
        </ChartCard>
    );
}

const countConfig = {
    count: { label: 'Companies', color: 'var(--chart-3)' },
} satisfies ChartConfig;

function FunnelChart({ data }: { data: DashboardProps['funnel'] }) {
    return (
        <ChartCard
            title="Funnel"
            description="Companies discovered in this range"
            empty={(data[0]?.count ?? 0) === 0}
        >
            <ChartContainer config={countConfig} className="h-[240px] w-full">
                <BarChart data={data} layout="vertical" margin={{ right: 40 }}>
                    <XAxis type="number" hide allowDecimals={false} />
                    <YAxis
                        type="category"
                        dataKey="label"
                        tickLine={false}
                        axisLine={false}
                        width={110}
                    />
                    <ChartTooltip
                        content={<ChartTooltipContent hideIndicator />}
                    />
                    <Bar
                        dataKey="count"
                        fill="var(--color-count)"
                        radius={[0, 3, 3, 0]}
                        maxBarSize={28}
                    >
                        <LabelList
                            dataKey="count"
                            position="right"
                            className="fill-foreground"
                            fontSize={12}
                        />
                    </Bar>
                </BarChart>
            </ChartContainer>
        </ChartCard>
    );
}

const rejectConfig = {
    count: { label: 'Rejected', color: 'var(--chart-5)' },
} satisfies ChartConfig;

function RejectReasonsChart({
    data,
}: {
    data: DashboardProps['rejectReasons'];
}) {
    return (
        <ChartCard
            title="Reject reasons"
            description="Why discovered companies were dropped"
            empty={data.length === 0}
        >
            <ChartContainer config={rejectConfig} className="h-[240px] w-full">
                <BarChart data={data} layout="vertical" margin={{ right: 40 }}>
                    <XAxis type="number" hide allowDecimals={false} />
                    <YAxis
                        type="category"
                        dataKey="label"
                        tickLine={false}
                        axisLine={false}
                        width={140}
                    />
                    <ChartTooltip
                        content={<ChartTooltipContent hideIndicator />}
                    />
                    <Bar
                        dataKey="count"
                        fill="var(--color-count)"
                        radius={[0, 3, 3, 0]}
                        maxBarSize={28}
                    >
                        <LabelList
                            dataKey="count"
                            position="right"
                            className="fill-foreground"
                            fontSize={12}
                        />
                    </Bar>
                </BarChart>
            </ChartContainer>
        </ChartCard>
    );
}

const scoreConfig = {
    count: { label: 'Companies', color: 'var(--chart-4)' },
} satisfies ChartConfig;

function ScoreDistributionChart({
    data,
}: {
    data: DashboardProps['scoreDistribution'];
}) {
    return (
        <ChartCard
            title="Score distribution"
            description="Scored companies discovered in this range"
            empty={data.every((row) => row.count === 0)}
        >
            <ChartContainer config={scoreConfig} className="h-[240px] w-full">
                <BarChart data={data} margin={{ top: 12 }}>
                    <CartesianGrid vertical={false} />
                    <XAxis dataKey="bucket" tickLine={false} axisLine={false} />
                    <YAxis
                        allowDecimals={false}
                        tickLine={false}
                        axisLine={false}
                        width={32}
                    />
                    <ChartTooltip
                        content={
                            <ChartTooltipContent
                                hideIndicator
                                labelFormatter={(value) =>
                                    Number(value) === 10
                                        ? 'Score 10'
                                        : `Score ${value}–${value}.9`
                                }
                            />
                        }
                    />
                    <Bar
                        dataKey="count"
                        fill="var(--color-count)"
                        radius={[3, 3, 0, 0]}
                    />
                </BarChart>
            </ChartContainer>
        </ChartCard>
    );
}

function SourcesChart({ data }: { data: DashboardProps['sources'] }) {
    const config = Object.fromEntries(
        data.map((row, i) => [
            row.source,
            {
                label: row.label,
                color: SOURCE_COLOURS[i % SOURCE_COLOURS.length],
            },
        ]),
    ) satisfies ChartConfig;

    return (
        <ChartCard
            title="Source split"
            description="Signals ingested in this range"
            empty={data.length === 0}
        >
            <ChartContainer config={config} className="h-[240px] w-full">
                <PieChart>
                    <ChartTooltip
                        content={<ChartTooltipContent nameKey="source" />}
                    />
                    <Pie
                        data={data}
                        dataKey="count"
                        nameKey="source"
                        innerRadius={50}
                        strokeWidth={2}
                    >
                        {data.map((row) => (
                            <Cell
                                key={row.source}
                                fill={`var(--color-${row.source})`}
                            />
                        ))}
                    </Pie>
                    <ChartLegend
                        content={<ChartLegendContent nameKey="source" />}
                    />
                </PieChart>
            </ChartContainer>
        </ChartCard>
    );
}

const RUN_STATUS: Record<
    DashboardRun['status'],
    {
        label: string;
        variant: 'default' | 'secondary' | 'destructive' | 'outline';
    }
> = {
    running: { label: 'Running', variant: 'secondary' },
    finished: { label: 'Finished', variant: 'outline' },
    stopped_target_reached: { label: 'Target reached', variant: 'default' },
    failed: { label: 'Failed', variant: 'destructive' },
};

function RunsPanel({ runs }: { runs: DashboardRun[] }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>Last runs</CardTitle>
                <CardDescription>
                    The five most recent discovery runs
                </CardDescription>
            </CardHeader>
            <CardContent>
                {runs.length === 0 ? (
                    <p className="text-sm text-muted-foreground">
                        No runs yet. Discovery runs daily at 06:10.
                    </p>
                ) : (
                    <ul className="divide-y">
                        {runs.map((run) => (
                            <li
                                key={run.id}
                                className="flex flex-col gap-1 py-2 first:pt-0 last:pb-0"
                            >
                                <div className="flex items-center justify-between gap-2">
                                    <span className="text-sm font-medium">
                                        {formatDateTime(run.startedAt)}
                                    </span>
                                    <Badge
                                        variant={RUN_STATUS[run.status].variant}
                                    >
                                        {RUN_STATUS[run.status].label}
                                    </Badge>
                                </div>
                                <div className="text-xs text-muted-foreground tabular-nums">
                                    {run.signalsFound} signals ·{' '}
                                    {run.companiesCreated} companies ·{' '}
                                    {run.qualified} qualified · {run.rejected}{' '}
                                    rejected ·{' '}
                                    {run.durationSeconds === null
                                        ? 'in progress'
                                        : formatDuration(run.durationSeconds)}
                                    {run.trigger === 'manual' && ' · manual'}
                                </div>
                            </li>
                        ))}
                    </ul>
                )}
            </CardContent>
        </Card>
    );
}

function ApiUsagePanel({
    usage,
    credits,
}: {
    usage: DashboardApiUsage[];
    credits: DashboardProps['credits'];
}) {
    return (
        <Card>
            <CardHeader>
                <CardTitle>API usage today</CardTitle>
                <CardDescription>Cost units against daily caps</CardDescription>
            </CardHeader>
            <CardContent className="flex flex-col gap-3">
                {usage.map((row) => {
                    const percent =
                        row.cap && row.cap > 0
                            ? Math.min(100, (row.used / row.cap) * 100)
                            : null;

                    return (
                        <div key={row.service} className="flex flex-col gap-1">
                            <div className="flex items-center justify-between text-sm">
                                <span>{row.label}</span>
                                <span className="text-muted-foreground tabular-nums">
                                    {row.cap !== null
                                        ? `${number.format(row.used)} / ${number.format(row.cap)}`
                                        : `${number.format(row.calls)} ${row.calls === 1 ? 'call' : 'calls'}`}
                                    {row.errors > 0 && (
                                        <span className="text-red-600 dark:text-red-400">
                                            {' '}
                                            · {row.errors}{' '}
                                            {row.errors === 1
                                                ? 'error'
                                                : 'errors'}
                                        </span>
                                    )}
                                </span>
                            </div>
                            {percent !== null && (
                                <div className="h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        className={`h-full rounded-full ${percent >= 90 ? 'bg-red-500' : 'bg-primary'}`}
                                        style={{ width: `${percent}%` }}
                                    />
                                </div>
                            )}
                        </div>
                    );
                })}

                <div className="mt-1 border-t pt-3 text-sm">
                    <div className="flex items-center justify-between">
                        <span>MillionVerifier credits</span>
                        <span className="font-medium tabular-nums">
                            {credits === null
                                ? '—'
                                : number.format(credits.credits)}
                        </span>
                    </div>
                    <p className="text-xs text-muted-foreground">
                        {credits?.at
                            ? `As of the last verification, ${formatDateTime(credits.at)}`
                            : 'Shown after the first verification.'}
                    </p>
                </div>
            </CardContent>
        </Card>
    );
}

function formatDate(date: string): string {
    return new Date(`${date}T12:00:00`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

function formatDay(date: string): string {
    return new Date(`${date}T12:00:00`).toLocaleDateString('en-GB', {
        day: 'numeric',
        month: 'short',
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

function formatDuration(seconds: number): string {
    if (seconds < 60) {
        return `${seconds}s`;
    }

    const minutes = Math.floor(seconds / 60);

    return minutes < 60
        ? `${minutes}m ${seconds % 60}s`
        : `${Math.floor(minutes / 60)}h ${minutes % 60}m`;
}
