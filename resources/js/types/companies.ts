export type Option = { value: string; label: string };

export type CompanyFilters = {
    status: string[];
    outreach: string[];
    type: string[];
    score_min: number | null;
    score_max: number | null;
    country: string | null;
    source: string | null;
    qualified_from: string | null;
    qualified_to: string | null;
    verified: boolean;
    linkedin: boolean;
    reject_reason: string | null;
    q: string | null;
    sort: 'score' | 'qualified_at' | 'name';
    dir: 'asc' | 'desc';
    per_page: number;
};

export type CompanyRow = {
    id: number;
    name: string;
    domain: string | null;
    website: string | null;
    score: number | null;
    type: string;
    location: string | null;
    country: string;
    registrationNumber: string | null;
    founders: {
        id: number;
        position: number;
        name: string;
        linkedinUrl: string | null;
        email: string | null;
        emailStatus: string | null;
    }[];
    contactEmail: { email: string; status: string } | null;
    signal: {
        title: string;
        source: string;
        url: string | null;
        postedAt: string | null;
    } | null;
    status: string;
    rejectReason: string | null;
    pipelineStep: string;
    outreachStatus: string;
    qualifiedAt: string | null;
};

export type CompaniesIndexProps = {
    filters: CompanyFilters;
    companies: {
        data: CompanyRow[];
        total: number;
        from: number | null;
        to: number | null;
        currentPage: number;
        lastPage: number;
        prevPageUrl: string | null;
        nextPageUrl: string | null;
    };
    options: {
        status: Option[];
        outreach: Option[];
        type: Option[];
        source: Option[];
        rejectReason: Option[];
        step: Option[];
        country: string[];
    };
};
