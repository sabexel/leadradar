<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Actions\ImportCompaniesAction;
use App\Actions\ImportContactedCsvAction;
use App\Enums\SuppressionReason;
use App\Models\Suppression;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

/**
 * Imports (docs/07 §4). Uploaded files are read from PHP's temporary upload and never stored (D98): the mapping UI
 * sends the same file twice — once to preview its columns, once to import with the chosen columns.
 */
final class ImportController extends Controller
{
    private const FILE_RULES = ['required', 'file', 'mimes:csv,txt', 'max:5120'];

    public function index(): Response
    {
        return Inertia::render('imports/index', [
            'suppressionCount' => Suppression::count(),
            'reasons' => array_map(fn (SuppressionReason $r): array => [
                'value' => $r->value,
                'label' => ucfirst(str_replace('_', ' ', $r->value)),
            ], SuppressionReason::cases()),
            'maxRows' => ImportCompaniesAction::MAX_ROWS,
        ]);
    }

    /**
     * The file's headers and the auto-detected columns, for the mapping UI.
     */
    public function preview(Request $request, ImportCompaniesAction $companies, ImportContactedCsvAction $suppressions): JsonResponse
    {
        $validated = $request->validate([
            'file' => self::FILE_RULES,
            'type' => ['required', Rule::in(['companies', 'suppressions'])],
        ]);

        $path = $this->path($request->file('file'));

        try {
            return response()->json($validated['type'] === 'companies' ? $companies->preview($path) : $suppressions->preview($path));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }
    }

    public function companies(Request $request, ImportCompaniesAction $import): RedirectResponse
    {
        $request->validate([
            'text' => ['required_without:file', 'nullable', 'string', 'max:100000'],
            'file' => ['required_without:text', 'nullable', 'file', 'mimes:csv,txt', 'max:5120'],
            'name_column' => ['nullable', 'string', 'max:200'],
            'website_column' => ['nullable', 'string', 'max:200'],
        ]);

        try {
            $result = $request->hasFile('file')
                ? $import->fromCsv(
                    $this->path($request->file('file')),
                    $request->filled('name_column') || $request->filled('website_column')
                        ? ['name' => $request->input('name_column'), 'website' => $request->input('website_column')]
                        : null,
                )
                : $import->fromText((string) $request->input('text'));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages([$request->hasFile('file') ? 'file' : 'text' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => $result->created > 0 ? 'success' : 'warning', 'message' => $result->summary()]);

        return back();
    }

    public function suppressions(Request $request, ImportContactedCsvAction $import): RedirectResponse
    {
        $validated = $request->validate([
            'file' => self::FILE_RULES,
            'reason' => ['required', Rule::enum(SuppressionReason::class)],
            'website' => ['array'],
            'website.*' => ['string', 'max:200'],
            'email' => ['array'],
            'email.*' => ['string', 'max:200'],
            'company_number' => ['array'],
            'company_number.*' => ['string', 'max:200'],
        ]);

        $mapping = $request->hasAny(['website', 'email', 'company_number']) ? [
            'website' => array_values($validated['website'] ?? []),
            'email' => array_values($validated['email'] ?? []),
            'company_number' => array_values($validated['company_number'] ?? []),
        ] : null;

        try {
            $result = $import->run($this->path($request->file('file')), $mapping, SuppressionReason::from((string) $validated['reason']));
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['file' => $e->getMessage()]);
        }

        Inertia::flash('toast', ['type' => 'success', 'message' => sprintf(
            '%d rows read. New suppressions: %d domains, %d emails, %d company numbers (%d already listed). %d existing %s stopped.',
            $result->rows,
            $result->domains,
            $result->emails,
            $result->companyNumbers,
            $result->alreadySuppressed,
            $result->leadsSuppressed,
            $result->leadsSuppressed === 1 ? 'lead' : 'leads',
        )]);

        return back();
    }

    private function path(?UploadedFile $file): string
    {
        if ($file === null || ! $file->isValid()) {
            throw ValidationException::withMessages(['file' => 'The upload failed — try again.']);
        }

        return $file->getRealPath() ?: throw ValidationException::withMessages(['file' => 'The upload failed — try again.']);
    }
}
