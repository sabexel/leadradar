<?php

declare(strict_types=1);

use App\DTOs\JobBoards\JobAdData;
use App\Enums\SignalSource;
use App\Services\Classification\RecruiterDetector;

beforeEach(fn () => $this->detector = new RecruiterDetector);

function adFor(string $employer, string $title = 'Laravel Developer', ?string $description = null): JobAdData
{
    return new JobAdData(SignalSource::Adzuna, '1', $title, $employer, null, null, $description, null, null, null, []);
}

it('flags recruiter employer names', function (string $name) {
    expect($this->detector->isRecruiterName($name))->toBeTrue();
})->with([
    'Sample Example Recruitment',
    'Example Recruiting Ltd',
    'Demo Resourcing Ltd',
    'Example Talent Partners',
    'Sample Staffing Solutions',
    'Example Search Partners',
    'Demo Executive Search Ltd',
    'Example Headhunters',
    'Sample People Ltd',
    'Example Personnel',
    'Demo Careers Ltd',
    'Example Selection Limited',
    'Sample Appointments',
]);

it('does not flag ordinary agency and product company names', function (string $name) {
    expect($this->detector->isRecruiterName($name))->toBeFalse();
})->with([
    'Example Digital Ltd',
    'Sample Software Studio Ltd',
    'Talented Example Web Ltd',   // "talented" is not "talent"
    'Example Peoplesoft Consulting', // no whole-word "people"
    'Demo Careersite Labs',
]);

it('flags ads posted on behalf of a client', function (?string $description) {
    expect($this->detector->isRecruiterAd(adFor('Example Consulting Ltd', 'Laravel Developer', $description)))->toBeTrue();
})->with([
    'We are recruiting on behalf of our client, a growing SaaS firm.',
    'My client is a fast-growing fintech in Leeds.',
    'Our client requires a Laravel developer.',
    'We are hiring for a client based in Manchester.',
]);

it('does not flag agencies talking about their clients', function () {
    $ad = adFor('Example Digital Ltd', 'Laravel Developer', 'We build platforms for our clients and need a Laravel developer.');

    expect($this->detector->isRecruiterAd($ad))->toBeFalse();
});

it('flags the employment-activity SIC codes', function () {
    expect($this->detector->hasRecruiterSicCode(['62012', '78109']))->toBeTrue()
        ->and($this->detector->hasRecruiterSicCode(['62012', '73110']))->toBeFalse()
        ->and($this->detector->hasRecruiterSicCode(null))->toBeFalse();
});
