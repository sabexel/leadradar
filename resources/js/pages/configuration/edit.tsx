import { Head, router, useForm } from '@inertiajs/react';
import { CheckCircle2, CircleAlert } from 'lucide-react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit, test, update } from '@/routes/configuration';

type Caps = {
    millionverifier_per_day: number;
    brave_per_day: number;
    adzuna_per_day: number;
    companies_house_per_5min: number;
    crawler_pages_per_company: number;
};

type Settings = {
    daily_qualified_target: number;
    qualify_threshold: number;
    signal_max_age_days: number;
    keywords: string[];
    countries: string[];
    caps: Caps;
};

type ConfigurationProps = {
    settings: Settings;
    services: { value: string; label: string; configured: boolean }[];
};

const CAP_FIELDS: { key: keyof Caps; label: string; hint: string }[] = [
    {
        key: 'millionverifier_per_day',
        label: 'MillionVerifier per day',
        hint: 'Credits (1 per address)',
    },
    { key: 'brave_per_day', label: 'Brave Search per day', hint: 'Queries' },
    { key: 'adzuna_per_day', label: 'Adzuna per day', hint: 'Requests' },
    {
        key: 'companies_house_per_5min',
        label: 'Companies House per 5 minutes',
        hint: 'Their limit is 600',
    },
    {
        key: 'crawler_pages_per_company',
        label: 'Pages crawled per company',
        hint: 'Including the homepage',
    },
];

export default function ConfigurationEdit({
    settings,
    services,
}: ConfigurationProps) {
    const form = useForm({
        daily_qualified_target: String(settings.daily_qualified_target),
        qualify_threshold: String(settings.qualify_threshold),
        signal_max_age_days: String(settings.signal_max_age_days),
        keywords: settings.keywords.join('\n'),
        countries: settings.countries.join(', '),
        caps: Object.fromEntries(
            Object.entries(settings.caps).map(([k, v]) => [k, String(v)]),
        ) as Record<keyof Caps, string>,
    });

    const errors = form.errors as Record<string, string | undefined>;

    const submit = (event: React.FormEvent) => {
        event.preventDefault();
        form.transform((data) => ({
            ...data,
            keywords: data.keywords.split('\n'),
            countries: data.countries.split(/[\s,]+/),
        }));
        form.put(update.url(), { preserveScroll: true });
    };

    return (
        <>
            <Head title="Settings" />
            <div className="flex h-full flex-1 flex-col gap-4 p-4 lg:max-w-4xl">
                <form onSubmit={submit} className="flex flex-col gap-4">
                    <Card>
                        <CardHeader>
                            <CardTitle>Targets</CardTitle>
                            <CardDescription>
                                Discovery stops for the day once the target is
                                reached.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-3">
                            <NumberField
                                id="daily_qualified_target"
                                label="Qualified leads per day"
                                value={form.data.daily_qualified_target}
                                onChange={(v) =>
                                    form.setData('daily_qualified_target', v)
                                }
                                error={errors.daily_qualified_target}
                                min={1}
                                max={500}
                            />
                            <NumberField
                                id="qualify_threshold"
                                label="Qualify threshold (0–10)"
                                value={form.data.qualify_threshold}
                                onChange={(v) =>
                                    form.setData('qualify_threshold', v)
                                }
                                error={errors.qualify_threshold}
                                min={0}
                                max={10}
                                step={0.1}
                            />
                            <NumberField
                                id="signal_max_age_days"
                                label="Max job-ad age (days)"
                                value={form.data.signal_max_age_days}
                                onChange={(v) =>
                                    form.setData('signal_max_age_days', v)
                                }
                                error={errors.signal_max_age_days}
                                min={1}
                                max={90}
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Search</CardTitle>
                            <CardDescription>
                                Keywords are searched on Reed and Adzuna, one
                                per line. Both boards are UK-only, so GB must be
                                in the countries.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2">
                            <div className="grid gap-2">
                                <Label htmlFor="keywords">Keywords</Label>
                                <textarea
                                    id="keywords"
                                    rows={4}
                                    value={form.data.keywords}
                                    onChange={(e) =>
                                        form.setData('keywords', e.target.value)
                                    }
                                    className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                                />
                                <InputError
                                    message={
                                        errors.keywords ??
                                        firstNested(errors, 'keywords.')
                                    }
                                />
                            </div>
                            <div className="grid content-start gap-2">
                                <Label htmlFor="countries">Countries</Label>
                                <Input
                                    id="countries"
                                    value={form.data.countries}
                                    onChange={(e) =>
                                        form.setData(
                                            'countries',
                                            e.target.value,
                                        )
                                    }
                                    placeholder="GB"
                                />
                                <InputError
                                    message={
                                        errors.countries ??
                                        firstNested(errors, 'countries.')
                                    }
                                />
                            </div>
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Daily caps</CardTitle>
                            <CardDescription>
                                Jobs that hit a cap wait until 06:00 the next
                                day. 0 stops that service.
                            </CardDescription>
                        </CardHeader>
                        <CardContent className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
                            {CAP_FIELDS.map((field) => (
                                <NumberField
                                    key={field.key}
                                    id={`caps.${field.key}`}
                                    label={field.label}
                                    hint={field.hint}
                                    value={form.data.caps[field.key]}
                                    onChange={(v) =>
                                        form.setData('caps', {
                                            ...form.data.caps,
                                            [field.key]: v,
                                        })
                                    }
                                    error={errors[`caps.${field.key}`]}
                                    min={0}
                                />
                            ))}
                        </CardContent>
                    </Card>

                    <div>
                        <Button type="submit" disabled={form.processing}>
                            Save settings
                        </Button>
                    </div>
                </form>

                <Card>
                    <CardHeader>
                        <CardTitle>API keys</CardTitle>
                        <CardDescription>
                            Keys live only in <code>.env</code> and are never
                            shown here. "Test connection" makes one cheap call,
                            counted in API usage.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        <ul className="divide-y">
                            {services.map((service) => (
                                <ServiceRow key={service.value} {...service} />
                            ))}
                        </ul>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

ConfigurationEdit.layout = {
    breadcrumbs: [{ title: 'Settings', href: edit() }],
};

function ServiceRow({
    value,
    label,
    configured,
}: {
    value: string;
    label: string;
    configured: boolean;
}) {
    const [testing, setTesting] = useState(false);

    return (
        <li className="flex flex-wrap items-center justify-between gap-3 py-3 first:pt-0 last:pb-0">
            <div className="flex items-center gap-2">
                {configured ? (
                    <CheckCircle2 className="size-4 text-emerald-600" />
                ) : (
                    <CircleAlert className="size-4 text-amber-600" />
                )}
                <span className="text-sm font-medium">{label}</span>
                <Badge variant={configured ? 'outline' : 'secondary'}>
                    {configured ? 'Configured' : 'Missing'}
                </Badge>
            </div>
            <Button
                size="sm"
                variant="outline"
                disabled={!configured || testing}
                onClick={() =>
                    router.post(
                        test.url(),
                        { service: value },
                        {
                            preserveScroll: true,
                            onStart: () => setTesting(true),
                            onFinish: () => setTesting(false),
                        },
                    )
                }
            >
                {testing ? 'Testing…' : 'Test connection'}
            </Button>
        </li>
    );
}

function NumberField({
    id,
    label,
    hint,
    value,
    onChange,
    error,
    min,
    max,
    step,
}: {
    id: string;
    label: string;
    hint?: string;
    value: string;
    onChange: (value: string) => void;
    error?: string;
    min?: number;
    max?: number;
    step?: number;
}) {
    return (
        <div className="grid content-start gap-2">
            <Label htmlFor={id}>{label}</Label>
            <Input
                id={id}
                type="number"
                inputMode="decimal"
                value={value}
                min={min}
                max={max}
                step={step}
                onChange={(e) => onChange(e.target.value)}
            />
            {hint && <p className="text-xs text-muted-foreground">{hint}</p>}
            <InputError message={error} />
        </div>
    );
}

function firstNested(
    errors: Record<string, string | undefined>,
    prefix: string,
): string | undefined {
    return Object.entries(errors).find(([key]) => key.startsWith(prefix))?.[1];
}
