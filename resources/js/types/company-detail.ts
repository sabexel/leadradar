import type { Option } from '@/types/companies';

export type EmailDetail = {
    id: number;
    email: string;
    kind: string;
    origin: string;
    sourceUrl: string | null;
    status: string;
    verifiedAt: string | null;
};

export type Evidence = {
    type: string;
    weight?: string;
    detail: string;
    url?: string | null;
};

export type ScoreLine = {
    key: string;
    points: number;
    max: number;
    items: Record<string, number>;
    daysOld: number | null;
};

export type CompanyDetailProps = {
    company: {
        id: number;
        name: string;
        legalName: string | null;
        website: string | null;
        domain: string | null;
        location: string | null;
        country: string;
        registeredAddress: string | null;
        registrationNumber: string | null;
        companiesHouseUrl: string | null;
        companyStatus: string | null;
        companyKind: string | null;
        incorporatedOn: string | null;
        sicCodes: string[];
        accountsCategory: string | null;
        type: string;
        status: string;
        rejectReason: string | null;
        pipelineStep: string;
        score: number | null;
        outreachStatus: string;
        contactedAt: string | null;
        qualifiedAt: string | null;
        notes: string | null;
        canRetry: boolean;
        contactEmail: EmailDetail | null;
        careersEmail: EmailDetail | null;
    };
    founders: {
        id: number;
        position: number;
        name: string;
        role: string;
        appointedOn: string | null;
        source: string;
        sourceUrl: string | null;
        linkedinUrl: string | null;
        linkedinSourceUrl: string | null;
        email: EmailDetail | null;
        candidates: EmailDetail[];
    }[];
    scoreBreakdown: {
        total: number;
        failedGate: string | null;
        lines: ScoreLine[];
    } | null;
    laravelEvidence: Evidence[];
    offshoreEvidence: Evidence[];
    signals: {
        id: number;
        title: string;
        source: string;
        employerName: string;
        location: string | null;
        url: string | null;
        postedAt: string | null;
    }[];
    events: {
        id: number;
        step: string;
        outcome: 'ok' | 'skipped' | 'failed' | 'rejected';
        message: string | null;
        context: Record<string, unknown> | null;
        createdAt: string | null;
    }[];
    options: {
        outreach: Option[];
        step: Option[];
    };
};
