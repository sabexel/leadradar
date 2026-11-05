import { Head, useForm } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { ToggleGroup, ToggleGroupItem } from '@/components/ui/toggle-group';
import { companies, index, preview, suppressions } from '@/routes/imports';

type ImportsProps = {
    suppressionCount: number;
    reasons: { value: string; label: string }[];
    maxRows: number;
};

type Preview<T> = { headers: string[]; rows: number; detected: T };

type CompanyColumns = { name: string | null; website: string | null };
type SuppressionColumns = {
    website: string[];
    email: string[];
    company_number: string[];
};

const NONE = '__none';

/**
 * Ask the server for a file's headers + detected columns. The file is read and discarded there (never stored).
 */
async function fetchPreview<T>(
    file: File,
    type: 'companies' | 'suppressions',
): Promise<{ preview?: Preview<T>; error?: string }> {
    const body = new FormData();
    body.append('file', file);
    body.append('type', type);

    const token = decodeURIComponent(
        document.cookie
            .split('; ')
            .find((c) => c.startsWith('XSRF-TOKEN='))
            ?.slice('XSRF-TOKEN='.length) ?? '',
    );

    const response = await fetch(preview.url(), {
        method: 'POST',
        body,
        headers: {
            Accept: 'application/json',
            'X-XSRF-TOKEN': token,
            'X-Requested-With': 'XMLHttpRequest',
        },
        credentials: 'same-origin',
    });

    const json = await response.json().catch(() => ({}));

    if (!response.ok) {
        return {
            error:
                json?.errors?.file?.[0] ??
                json?.message ??
                'Could not read the file.',
        };
    }

    return { preview: json as Preview<T> };
}

export default function ImportsIndex({
    suppressionCount,
    reasons,
    maxRows,
}: ImportsProps) {
    return (
        <>
            <Head title="Imports" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 lg:max-w-4xl">
                <CompanyImport maxRows={maxRows} />
                <SuppressionImport count={suppressionCount} reasons={reasons} />
                <p className="text-xs text-muted-foreground">
                    Uploaded files are read once and discarded — nothing is kept
                    on the server.
                </p>
            </div>
        </>
    );
}

ImportsIndex.layout = {
    breadcrumbs: [{ title: 'Imports', href: index() }],
};

function CompanyImport({ maxRows }: { maxRows: number }) {
    const [mode, setMode] = useState<'paste' | 'csv'>('paste');
    const [columns, setColumns] = useState<Preview<CompanyColumns> | null>(
        null,
    );
    const [previewError, setPreviewError] = useState<string | null>(null);
    const form = useForm<{
        text: string;
        file: File | null;
        name_column: string;
        website_column: string;
    }>({ text: '', file: null, name_column: '', website_column: '' });

    const choose = async (file: File | null) => {
        form.setData('file', file);
        setColumns(null);
        setPreviewError(null);

        if (!file) {
            return;
        }

        const { preview: result, error } = await fetchPreview<CompanyColumns>(
            file,
            'companies',
        );

        if (error) {
            setPreviewError(error);

            return;
        }

        if (result) {
            setColumns(result);
            form.setData((data) => ({
                ...data,
                file,
                name_column: result.detected.name ?? '',
                website_column: result.detected.website ?? '',
            }));
        }
    };

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) =>
            mode === 'paste'
                ? { text: data.text }
                : {
                      file: data.file,
                      name_column: data.name_column,
                      website_column: data.website_column,
                  },
        );
        form.post(companies.url(), {
            preserveScroll: true,
            forceFormData: mode === 'csv',
            onSuccess: () => {
                form.reset();
                setColumns(null);
            },
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Import companies</CardTitle>
                <CardDescription>
                    Each new company gets an "import" signal and goes through
                    the same pipeline as job-board finds. Companies already in
                    LeadRadar or on the do-not-contact list are skipped. Up to{' '}
                    {maxRows} per import.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <ToggleGroup
                        type="single"
                        variant="outline"
                        value={mode}
                        onValueChange={(v) =>
                            v && setMode(v as 'paste' | 'csv')
                        }
                        className="self-start"
                    >
                        <ToggleGroupItem value="paste" className="px-3">
                            Paste a list
                        </ToggleGroupItem>
                        <ToggleGroupItem value="csv" className="px-3">
                            Upload CSV
                        </ToggleGroupItem>
                    </ToggleGroup>

                    {mode === 'paste' ? (
                        <div className="grid gap-2">
                            <Label htmlFor="company-list">
                                One per line: a company name, a domain, or
                                "Name, domain"
                            </Label>
                            <textarea
                                id="company-list"
                                rows={8}
                                value={form.data.text}
                                onChange={(e) =>
                                    form.setData('text', e.target.value)
                                }
                                placeholder={
                                    'Example Digital Ltd\nsample-software.co.uk\nDemo Web Labs, demowebl.co.uk'
                                }
                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 font-mono text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                            <InputError message={form.errors.text} />
                        </div>
                    ) : (
                        <div className="grid gap-3">
                            <div className="grid gap-2">
                                <Label htmlFor="company-csv">CSV file</Label>
                                <Input
                                    id="company-csv"
                                    type="file"
                                    accept=".csv,text/csv"
                                    onChange={(e) =>
                                        void choose(e.target.files?.[0] ?? null)
                                    }
                                />
                                <InputError
                                    message={previewError ?? form.errors.file}
                                />
                            </div>

                            {columns && (
                                <div className="grid gap-3 rounded-lg border p-3 sm:grid-cols-2">
                                    <p className="text-sm text-muted-foreground sm:col-span-2">
                                        {columns.rows} rows. Check the columns:
                                    </p>
                                    <ColumnSelect
                                        label="Company name column"
                                        headers={columns.headers}
                                        value={form.data.name_column}
                                        onChange={(v) =>
                                            form.setData('name_column', v)
                                        }
                                    />
                                    <ColumnSelect
                                        label="Website / domain column"
                                        headers={columns.headers}
                                        value={form.data.website_column}
                                        onChange={(v) =>
                                            form.setData('website_column', v)
                                        }
                                    />
                                </div>
                            )}
                        </div>
                    )}

                    <div>
                        <Button
                            type="submit"
                            disabled={
                                form.processing ||
                                (mode === 'paste'
                                    ? form.data.text.trim() === ''
                                    : !columns ||
                                      (form.data.name_column === '' &&
                                          form.data.website_column === ''))
                            }
                        >
                            Import companies
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}

function ColumnSelect({
    label,
    headers,
    value,
    onChange,
}: {
    label: string;
    headers: string[];
    value: string;
    onChange: (value: string) => void;
}) {
    return (
        <div className="grid gap-2">
            <Label>{label}</Label>
            <Select
                value={value === '' ? NONE : value}
                onValueChange={(v) => onChange(v === NONE ? '' : v)}
            >
                <SelectTrigger aria-label={label}>
                    <SelectValue />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem value={NONE}>— none —</SelectItem>
                    {headers
                        .filter((h) => h !== '')
                        .map((header) => (
                            <SelectItem key={header} value={header}>
                                {header}
                            </SelectItem>
                        ))}
                </SelectContent>
            </Select>
        </div>
    );
}

const KINDS: { key: keyof SuppressionColumns; label: string }[] = [
    { key: 'website', label: 'Website' },
    { key: 'email', label: 'Email' },
    { key: 'company_number', label: 'Company number' },
];

function SuppressionImport({
    count,
    reasons,
}: {
    count: number;
    reasons: ImportsProps['reasons'];
}) {
    const [columns, setColumns] = useState<Preview<SuppressionColumns> | null>(
        null,
    );
    const [previewError, setPreviewError] = useState<string | null>(null);
    const form = useForm<{
        file: File | null;
        reason: string;
        website: string[];
        email: string[];
        company_number: string[];
    }>({
        file: null,
        reason: 'already_contacted',
        website: [],
        email: [],
        company_number: [],
    });

    const choose = async (file: File | null) => {
        form.setData('file', file);
        setColumns(null);
        setPreviewError(null);

        if (!file) {
            return;
        }

        const { preview: result, error } =
            await fetchPreview<SuppressionColumns>(file, 'suppressions');

        if (error) {
            setPreviewError(error);

            return;
        }

        if (result) {
            setColumns(result);
            form.setData((data) => ({ ...data, file, ...result.detected }));
        }
    };

    const toggle = (kind: keyof SuppressionColumns, header: string) => {
        const current = form.data[kind];
        form.setData(
            kind,
            current.includes(header)
                ? current.filter((h) => h !== header)
                : [...current, header],
        );
    };

    const picked =
        form.data.website.length +
        form.data.email.length +
        form.data.company_number.length;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.post(suppressions.url(), {
            preserveScroll: true,
            forceFormData: true,
            onSuccess: () => {
                form.reset();
                setColumns(null);
            },
        });
    };

    return (
        <Card>
            <CardHeader>
                <CardTitle>Suppression import</CardTitle>
                <CardDescription>
                    Upload your outreach sheet (CSV). Every website, email and
                    company number in the chosen columns goes on the
                    do-not-contact list, and matching leads stop.{' '}
                    {count.toLocaleString('en-GB')} on the list now.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <div className="grid gap-2 sm:max-w-md">
                        <Label htmlFor="suppression-csv">CSV file</Label>
                        <Input
                            id="suppression-csv"
                            type="file"
                            accept=".csv,text/csv"
                            onChange={(e) =>
                                void choose(e.target.files?.[0] ?? null)
                            }
                        />
                        <InputError
                            message={previewError ?? form.errors.file}
                        />
                    </div>

                    {columns && (
                        <div className="flex flex-col gap-3 rounded-lg border p-3">
                            <p className="text-sm text-muted-foreground">
                                {columns.rows} rows. Tick the columns to read
                                (job-board and LinkedIn links are never treated
                                as websites):
                            </p>
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="text-left text-muted-foreground">
                                            <th className="py-1 pr-4 font-normal">
                                                Column
                                            </th>
                                            {KINDS.map((kind) => (
                                                <th
                                                    key={kind.key}
                                                    className="px-2 py-1 text-center font-normal"
                                                >
                                                    {kind.label}
                                                </th>
                                            ))}
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {columns.headers
                                            .filter((h) => h !== '')
                                            .map((header) => (
                                                <tr
                                                    key={header}
                                                    className="border-t"
                                                >
                                                    <td className="py-1.5 pr-4">
                                                        {header}
                                                    </td>
                                                    {KINDS.map((kind) => (
                                                        <td
                                                            key={kind.key}
                                                            className="px-2 text-center"
                                                        >
                                                            <Checkbox
                                                                aria-label={`${header} as ${kind.label}`}
                                                                checked={form.data[
                                                                    kind.key
                                                                ].includes(
                                                                    header,
                                                                )}
                                                                onCheckedChange={() =>
                                                                    toggle(
                                                                        kind.key,
                                                                        header,
                                                                    )
                                                                }
                                                            />
                                                        </td>
                                                    ))}
                                                </tr>
                                            ))}
                                    </tbody>
                                </table>
                            </div>
                            <div className="grid gap-2 sm:max-w-xs">
                                <Label>Reason</Label>
                                <Select
                                    value={form.data.reason}
                                    onValueChange={(v) =>
                                        form.setData('reason', v)
                                    }
                                >
                                    <SelectTrigger aria-label="Reason">
                                        <SelectValue />
                                    </SelectTrigger>
                                    <SelectContent>
                                        {reasons.map((reason) => (
                                            <SelectItem
                                                key={reason.value}
                                                value={reason.value}
                                            >
                                                {reason.label}
                                            </SelectItem>
                                        ))}
                                    </SelectContent>
                                </Select>
                            </div>
                        </div>
                    )}

                    <div>
                        <Button
                            type="submit"
                            disabled={
                                form.processing || !columns || picked === 0
                            }
                        >
                            Import suppressions
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>
    );
}
