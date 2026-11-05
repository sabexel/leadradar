export type RangePreset = 'today' | '7d' | '30d' | 'custom';

export type DashboardRange = {
    preset: RangePreset;
    from: string;
    to: string;
};

export type DashboardStats = {
    scraped: number;
    discovered: number;
    qualified: number;
    rejected: number;
    inProgress: number;
    errors: number;
    averageScore: number | null;
    verifiedFounderEmails: number;
    contacted: number;
    replied: number;
};

export type DashboardRun = {
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

export type DashboardApiUsage = {
    service: string;
    label: string;
    calls: number;
    used: number;
    cap: number | null;
    errors: number;
};

export type DashboardProps = {
    range: DashboardRange;
    stats: DashboardStats;
    qualifiedPerDay: {
        target: number;
        days: { date: string; qualified: number }[];
    };
    funnel: { stage: string; label: string; count: number }[];
    rejectReasons: { reason: string; label: string; count: number }[];
    scoreDistribution: { bucket: number; count: number }[];
    sources: { source: string; label: string; count: number }[];
    runs: DashboardRun[];
    apiUsage: DashboardApiUsage[];
    credits: { credits: number; at: string | null } | null;
};
