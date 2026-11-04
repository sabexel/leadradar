<?php

declare(strict_types=1);

use App\DTOs\Classification\ClassificationInput;
use App\Enums\CompanyType;
use App\Services\Classification\CompanyClassifier;
use App\Services\Classification\RecruiterDetector;
use App\Services\Crawler\OffshoreSignalDetector;

beforeEach(fn () => $this->classifier = new CompanyClassifier(new RecruiterDetector, new OffshoreSignalDetector));

function classificationText(string $name): string
{
    return (string) file_get_contents(dirname(__DIR__, 2)."/Fixtures/classification/{$name}");
}

function classify(ClassificationInput $input): CompanyType
{
    return test()->classifier->classify($input)->type;
}

it('classifies a web agency from SIC + agency language in its ad', function () {
    $result = $this->classifier->classify(new ClassificationInput(
        name: 'EXAMPLE DIGITAL LTD',
        sicCodes: ['62012'],
        adTexts: [classificationText('agency-ad.txt')],
        officerCountries: ['England'],
    ));

    expect($result->type)->toBe(CompanyType::Agency)
        ->and($result->offshoreSignals)->toBe([])
        ->and($result->reasons)->not->toBeEmpty();
});

it('classifies an explicit "digital agency" even without an agency SIC code', function () {
    expect(classify(new ClassificationInput('EXAMPLE CONSULTING LTD', ['70229'], ['We are a digital agency hiring a Laravel developer.'])))
        ->toBe(CompanyType::Agency);
});

it('classifies an in-house product company as a direct client', function () {
    expect(classify(new ClassificationInput(
        name: 'SAMPLE SCHOOLS SOFTWARE LTD',
        sicCodes: ['62012'],
        adTexts: [classificationText('product-ad.txt')],
        officerCountries: ['England', 'Wales'],
    )))->toBe(CompanyType::DirectClient);
});

it('classifies recruiters by name or SIC code', function (ClassificationInput $input) {
    expect(classify($input))->toBe(CompanyType::Recruiter);
})->with([
    'name' => fn () => new ClassificationInput('SAMPLE TALENT PARTNERS LTD', ['62012'], [classificationText('agency-ad.txt')]),
    'sic' => fn () => new ClassificationInput('EXAMPLE GROUP LTD', ['78109']),
]);

it('classifies offshore shops from strong signals', function (ClassificationInput $input) {
    $result = $this->classifier->classify($input);

    expect($result->type)->toBe(CompanyType::Offshore)
        ->and($result->offshoreSignals)->not->toBeEmpty();
})->with([
    'hourly pricing' => fn () => new ClassificationInput('SAMPLE CODE LABS LTD', ['62012'], siteTexts: [classificationText('hourly-pricing.txt')]),
    'delivery centre + officers abroad' => fn () => new ClassificationInput(
        'SAMPLE CODE LABS LTD', ['62012'], [classificationText('offshore-site.txt')], ['India'],
    ),
    'outsourcing language + officers abroad' => fn () => new ClassificationInput(
        'SAMPLE CODE LABS LTD', ['62012'], [classificationText('weak-outsourcing.txt')], ['Ukraine'],
    ),
]);

it('does not call a company offshore on one medium signal alone', function () {
    $result = $this->classifier->classify(new ClassificationInput('EXAMPLE DIGITAL LTD', ['62012'], [classificationText('offshore-site.txt')], ['England']));

    expect($result->type)->not->toBe(CompanyType::Offshore)
        ->and($result->offshoreSignals)->toHaveCount(1); // kept as evidence for the dashboard
});

it('does not call a UK company offshore just because its officers live abroad', function () {
    expect(classify(new ClassificationInput('EXAMPLE DIGITAL LTD', ['62012'], [classificationText('agency-ad.txt')], ['Spain'])))
        ->toBe(CompanyType::Agency);
});

it('returns unknown with nothing to go on', function () {
    expect(classify(new ClassificationInput('EXAMPLE HOLDINGS LTD', ['70100'])))->toBe(CompanyType::Unknown);
});

it('returns unknown when agency language has no agency SIC to back it', function () {
    expect(classify(new ClassificationInput('EXAMPLE HOLDINGS LTD', ['70229'], ['Laravel developer to work on client projects.'])))
        ->toBe(CompanyType::Unknown);
});
