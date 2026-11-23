import { Check, Copy, Mail } from 'lucide-react';
import { useState } from 'react';
import { toast } from 'sonner';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useClipboard } from '@/hooks/use-clipboard';
import {
    draftOutreachEmail,
    type OutreachDraftInput,
} from '@/lib/draft-outreach-email';
import { cn } from '@/lib/utils';

type EmailActionsProps = {
    email: string;
    verified: boolean;
    draft: Omit<OutreachDraftInput, 'recipientEmail'>;
    className?: string;
};

/**
 * Compact copy + “generate email” controls shown after a Verified badge on the leads table.
 */
export function EmailActions({
    email,
    verified,
    draft,
    className,
}: EmailActionsProps) {
    const [, copy] = useClipboard();
    const [copied, setCopied] = useState(false);
    const [open, setOpen] = useState(false);
    const [subject, setSubject] = useState('');
    const [body, setBody] = useState('');
    const [consentWarning, setConsentWarning] = useState(false);

    if (!verified) {
        return null;
    }

    const copyAddress = async () => {
        const ok = await copy(email);

        if (ok) {
            setCopied(true);
            toast.success('Email copied');
            window.setTimeout(() => setCopied(false), 1500);
        } else {
            toast.error('Could not copy email');
        }
    };

    const openDraft = () => {
        const generated = draftOutreachEmail({
            ...draft,
            recipientEmail: email,
        });
        setSubject(generated.subject);
        setBody(generated.body);
        setConsentWarning(generated.consentWarning);
        setOpen(true);
    };

    const copySubject = async () => {
        const ok = await copy(subject);
        toast[ok ? 'success' : 'error'](
            ok ? 'Subject copied' : 'Could not copy subject',
        );
    };

    const copyBody = async () => {
        const ok = await copy(body);
        toast[ok ? 'success' : 'error'](
            ok ? 'Email body copied' : 'Could not copy body',
        );
    };

    const copyAll = async () => {
        const ok = await copy(`To: ${email}\nSubject: ${subject}\n\n${body}`);
        toast[ok ? 'success' : 'error'](
            ok ? 'Full message copied' : 'Could not copy',
        );
    };

    const mailto = `mailto:${encodeURIComponent(email)}?subject=${encodeURIComponent(subject)}&body=${encodeURIComponent(body)}`;

    return (
        <span className={cn('inline-flex items-center gap-0.5', className)}>
            <button
                type="button"
                onClick={() => void copyAddress()}
                className="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                aria-label={`Copy ${email}`}
                title="Copy email"
            >
                {copied ? (
                    <Check className="size-3.5 text-emerald-600" />
                ) : (
                    <Copy className="size-3.5" />
                )}
            </button>
            <button
                type="button"
                onClick={openDraft}
                className="rounded p-0.5 text-muted-foreground hover:bg-muted hover:text-foreground"
                aria-label={`Generate email for ${email}`}
                title="Generate email"
            >
                <Mail className="size-3.5" />
            </button>

            <Dialog open={open} onOpenChange={setOpen}>
                <DialogContent className="sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>Outreach email</DialogTitle>
                        <DialogDescription>
                            Edit if you want, then copy into Outlook or
                            Mailmeteor, or open your mail app.
                        </DialogDescription>
                    </DialogHeader>

                    {consentWarning && (
                        <p className="rounded-md border border-amber-200 bg-amber-50 px-3 py-2 text-sm text-amber-900 dark:border-amber-900 dark:bg-amber-950 dark:text-amber-200">
                            This lead may need prior consent for cold email
                            (PECR). Prefer phone if you have it.
                        </p>
                    )}

                    <div className="grid gap-3">
                        <div className="grid gap-1.5">
                            <Label htmlFor="outreach-to">To</Label>
                            <Input id="outreach-to" value={email} readOnly />
                        </div>
                        <div className="grid gap-1.5">
                            <div className="flex items-center justify-between gap-2">
                                <Label htmlFor="outreach-subject">
                                    Subject
                                </Label>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 px-2 text-xs"
                                    onClick={() => void copySubject()}
                                >
                                    Copy subject
                                </Button>
                            </div>
                            <Input
                                id="outreach-subject"
                                value={subject}
                                onChange={(e) => setSubject(e.target.value)}
                            />
                        </div>
                        <div className="grid gap-1.5">
                            <div className="flex items-center justify-between gap-2">
                                <Label htmlFor="outreach-body">Body</Label>
                                <Button
                                    type="button"
                                    variant="ghost"
                                    size="sm"
                                    className="h-7 px-2 text-xs"
                                    onClick={() => void copyBody()}
                                >
                                    Copy body
                                </Button>
                            </div>
                            <textarea
                                id="outreach-body"
                                rows={12}
                                value={body}
                                onChange={(e) => setBody(e.target.value)}
                                className="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                            />
                        </div>
                    </div>

                    <DialogFooter className="gap-2 sm:justify-between">
                        <Button type="button" variant="outline" asChild>
                            <a href={mailto}>Open in mail app</a>
                        </Button>
                        <div className="flex gap-2">
                            <Button
                                type="button"
                                variant="secondary"
                                onClick={() => void copyAll()}
                            >
                                Copy all
                            </Button>
                            <Button
                                type="button"
                                onClick={() => setOpen(false)}
                            >
                                Done
                            </Button>
                        </div>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </span>
    );
}
