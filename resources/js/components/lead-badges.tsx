import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';

/**
 * Score bands from docs/06: 8–10 Excellent (green), 6–7.9 Good (blue), 4–5.9 Near miss (amber).
 */
export function scoreBand(
    score: number | null,
): 'excellent' | 'good' | 'near_miss' | 'low' | null {
    if (score === null) {
        return null;
    }

    if (score >= 8) {
        return 'excellent';
    }

    if (score >= 6) {
        return 'good';
    }

    return score >= 4 ? 'near_miss' : 'low';
}

const SCORE_CLASSES = {
    excellent:
        'border-emerald-200 bg-emerald-50 text-emerald-700 dark:border-emerald-900 dark:bg-emerald-950 dark:text-emerald-300',
    good: 'border-blue-200 bg-blue-50 text-blue-700 dark:border-blue-900 dark:bg-blue-950 dark:text-blue-300',
    near_miss:
        'border-amber-200 bg-amber-50 text-amber-700 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-300',
    low: 'text-muted-foreground',
};

export function ScoreBadge({ score }: { score: number | null }) {
    const band = scoreBand(score);

    if (band === null || score === null) {
        return <span className="text-muted-foreground">—</span>;
    }

    return (
        <Badge
            variant="outline"
            className={cn('tabular-nums', SCORE_CLASSES[band])}
        >
            {score.toFixed(1)}
        </Badge>
    );
}

const VERIFICATION: Record<string, { label: string; className: string }> = {
    ok: {
        label: 'Verified',
        className: 'text-emerald-700 dark:text-emerald-400',
    },
    catch_all: {
        label: 'Catch-all',
        className: 'text-amber-700 dark:text-amber-400',
    },
    unknown: { label: 'Unknown', className: 'text-muted-foreground' },
    unverified: { label: 'Unverified', className: 'text-muted-foreground' },
    invalid: { label: 'Invalid', className: 'text-red-700 dark:text-red-400' },
    disposable: {
        label: 'Disposable',
        className: 'text-red-700 dark:text-red-400',
    },
    error: { label: 'Error', className: 'text-red-700 dark:text-red-400' },
};

export function VerificationBadge({ status }: { status: string | null }) {
    if (status === null) {
        return null;
    }

    const item = VERIFICATION[status] ?? {
        label: status,
        className: 'text-muted-foreground',
    };

    return (
        <span className={cn('text-xs font-medium', item.className)}>
            {item.label}
        </span>
    );
}

const STATUS_VARIANTS: Record<
    string,
    'default' | 'secondary' | 'destructive' | 'outline'
> = {
    qualified: 'default',
    enriching: 'secondary',
    discovered: 'secondary',
    rejected: 'outline',
    suppressed: 'outline',
    error: 'destructive',
};

export function StatusBadge({ status }: { status: string }) {
    return (
        <Badge variant={STATUS_VARIANTS[status] ?? 'outline'}>
            {humanise(status)}
        </Badge>
    );
}

export function humanise(value: string): string {
    const text = value.replace(/[_-]/g, ' ');

    return text.charAt(0).toUpperCase() + text.slice(1);
}
