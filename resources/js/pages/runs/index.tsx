import { Head, Link, router } from '@inertiajs/react';
import { Play, RotateCcw, Trash2 } from 'lucide-react';
import { useState } from 'react';
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
    Table,
    TableBody,
    TableCell,
    TableHead,
    TableHeader,
    TableRow,
} from '@/components/ui/table';
import { discover, index } from '@/routes/runs';
import { forget, retry } from '@/routes/runs/failed';

type Run = {
    id: number;
    startedAt: string;
    finishedAt: string | null;
    durationSeconds: number | null;
    trigger: string;
    status: 'running' | 'finished' | 'stopped_target_reached' | 'failed';
    signalsFound: number;
    companiesCreated: number;
    qualified: number;
    rejected: number;
};

type Usage = { calls: number; cost: number; errors: number };

type RunsProps = {
    runs: {
        data: Run[];
        total: number;
        currentPage: number;
        lastPage: number;
        prevPageUrl: string | null;
        nextPageUrl: string | null;
    };
    apiUsage: {
        services: { value: string; label: string }[];
        days: { date: string; services: Record<string, Usage> }[];
    };
    failedJobs: {
        uuid: string;
        job: string;
        queue: string;
        error: string;
        failedAt: string;
    }[];
    failedJobCount: number;
};

const RUN_STATUS: Record<
    Run['status'],
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

const number = new Intl.NumberFormat('en-GB', { maximumFractionDigits: 2 });

export default function RunsIndex({
    runs,
    apiUsage,
    failedJobs,
    failedJobCount,
}: RunsProps) {
    const [starting, setStarting] = useState(false);

    return (
        <>
            <Head title="Runs" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <p className="text-sm text-muted-foreground">
                        Discovery runs daily at 06:10 (London). Running it now
                        queues the same job-board searches.
                    </p>
                    <Button
                        disabled={starting}
                        onClick={() =>
                            router.post(
                                discover.url(),
                                {},
                                {
                                    preserveScroll: true,
                                    onStart: () => setStarting(true),
                                    onFinish: () => setStarting(false),
                                },
                            )
                        }
                    >
                        <Play className="size-4" /> Run discovery now
                    </Button>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Discovery runs</CardTitle>
                        <CardDescription>{runs.total} in total</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>#</TableHead>
                                        <TableHead>Started</TableHead>
                                        <TableHead>Trigger</TableHead>
                                        <TableHead>Status</TableHead>
                                        <TableHead className="text-right">
                                            Signals
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Companies
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Qualified
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Rejected
                                        </TableHead>
                                        <TableHead className="text-right">
                                            Duration
                                        </TableHead>
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {runs.data.length === 0 && (
                                        <TableRow>
                                            <TableCell
                                                colSpan={9}
                                                className="h-20 text-center text-muted-foreground"
                                            >
                                                No runs yet.
                                            </TableCell>
                                        </TableRow>
                                    )}
                                    {runs.data.map((run) => (
                                        <TableRow key={run.id}>
                                            <TableCell className="tabular-nums">
                                                {run.id}
                                            </TableCell>
                                            <TableCell className="whitespace-nowrap">
                                                {formatDateTime(run.startedAt)}
                                            </TableCell>
                                            <TableCell className="capitalize">
                                                {run.trigger}
                                            </TableCell>
                                            <TableCell>
                                                <Badge
                                                    variant={
                                                        RUN_STATUS[run.status]
                                                            .variant
                                                    }
                                                >
                                                    {
                                                        RUN_STATUS[run.status]
                                                            .label
                                                    }
                                                </Badge>
                                            </TableCell>
                                            <Num value={run.signalsFound} />
                                            <Num value={run.companiesCreated} />
                                            <Num value={run.qualified} />
                                            <Num value={run.rejected} />
                                            <TableCell className="text-right whitespace-nowrap tabular-nums">
                                                {run.durationSeconds === null
                                                    ? '—'
                                                    : formatDuration(
                                                          run.durationSeconds,
                                                      )}
                                            </TableCell>
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                        {runs.lastPage > 1 && (
                            <div className="mt-3 flex items-center justify-end gap-2 text-sm">
                                <PageLink
                                    href={runs.prevPageUrl}
                                    label="Previous"
                                />
                                <span className="text-muted-foreground tabular-nums">
                                    {runs.currentPage} / {runs.lastPage}
                                </span>
                                <PageLink
                                    href={runs.nextPageUrl}
                                    label="Next"
                                />
                            </div>
                        )}
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>API usage per day</CardTitle>
                        <CardDescription>
                            Calls · cost units · errors, last 14 days (London)
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <div className="overflow-x-auto">
                            <Table>
                                <TableHeader>
                                    <TableRow>
                                        <TableHead>Day</TableHead>
                                        {apiUsage.services.map((service) => (
                                            <TableHead
                                                key={service.value}
                                                className="text-right"
                                            >
                                                {service.label}
                                            </TableHead>
                                        ))}
                                    </TableRow>
                                </TableHeader>
                                <TableBody>
                                    {apiUsage.days.map((day) => (
                                        <TableRow key={day.date}>
                                            <TableCell className="whitespace-nowrap">
                                                {formatDay(day.date)}
                                            </TableCell>
                                            {apiUsage.services.map(
                                                (service) => (
                                                    <UsageCell
                                                        key={service.value}
                                                        usage={
                                                            day.services[
                                                                service.value
                                                            ]
                                                        }
                                                    />
                                                ),
                                            )}
                                        </TableRow>
                                    ))}
                                </TableBody>
                            </Table>
                        </div>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle>Failed jobs</CardTitle>
                        <CardDescription>
                            {failedJobCount === 0
                                ? 'None — all queued work finished.'
                                : `${failedJobCount} failed ${failedJobCount === 1 ? 'job' : 'jobs'}${failedJobCount > failedJobs.length ? ` (newest ${failedJobs.length} shown)` : ''}. Failed jobs are pruned after 7 days.`}
                        </CardDescription>
                    </CardHeader>
                    {failedJobs.length > 0 && (
                        <CardContent>
                            <ul className="divide-y">
                                {failedJobs.map((job) => (
                                    <li
                                        key={job.uuid}
                                        className="flex flex-wrap items-start justify-between gap-3 py-3 first:pt-0 last:pb-0"
                                    >
                                        <div className="min-w-0 flex-1">
                                            <div className="text-sm font-medium">
                                                {job.job}{' '}
                                                <span className="font-normal text-muted-foreground">
                                                    · {job.queue} ·{' '}
                                                    {formatDateTime(
                                                        job.failedAt,
                                                    )}
                                                </span>
                                            </div>
                                            <div className="text-xs break-words text-muted-foreground">
                                                {job.error}
                                            </div>
                                        </div>
                                        <div className="flex gap-2">
                                            <Button
                                                size="sm"
                                                variant="outline"
                                                onClick={() =>
                                                    router.post(
                                                        retry.url(job.uuid),
                                                        {},
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <RotateCcw className="size-3.5" />
                                                Retry
                                            </Button>
                                            <Button
                                                size="sm"
                                                variant="ghost"
                                                aria-label="Remove failed job"
                                                onClick={() =>
                                                    router.delete(
                                                        forget.url(job.uuid),
                                                        {
                                                            preserveScroll: true,
                                                        },
                                                    )
                                                }
                                            >
                                                <Trash2 className="size-3.5" />
                                            </Button>
                                        </div>
                                    </li>
                                ))}
                            </ul>
                        </CardContent>
                    )}
                </Card>
            </div>
        </>
    );
}

RunsIndex.layout = {
    breadcrumbs: [{ title: 'Runs', href: index() }],
};

function Num({ value }: { value: number }) {
    return (
        <TableCell className="text-right tabular-nums">
            {number.format(value)}
        </TableCell>
    );
}

function UsageCell({ usage }: { usage: Usage | undefined }) {
    if (!usage || usage.calls === 0) {
        return (
            <TableCell className="text-right text-muted-foreground">
                —
            </TableCell>
        );
    }

    return (
        <TableCell className="text-right whitespace-nowrap tabular-nums">
            {number.format(usage.calls)}
            <span className="text-muted-foreground">
                {' · '}
                {number.format(usage.cost)}
            </span>
            {usage.errors > 0 && (
                <span className="text-red-600 dark:text-red-400">
                    {' · '}
                    {usage.errors}
                </span>
            )}
        </TableCell>
    );
}

function PageLink({ href, label }: { href: string | null; label: string }) {
    return href ? (
        <Button size="sm" variant="outline" asChild>
            <Link href={href} preserveScroll>
                {label}
            </Link>
        </Button>
    ) : (
        <Button size="sm" variant="outline" disabled>
            {label}
        </Button>
    );
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

function formatDay(date: string): string {
    return new Date(`${date}T12:00:00`).toLocaleDateString('en-GB', {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
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
