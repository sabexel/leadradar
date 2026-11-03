<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\EmailVerificationStatus;
use App\Enums\RejectReason;
use App\Models\Company;
use App\Models\EmailAddress;
use App\Models\Person;
use App\Models\Signal;
use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(SettingsSeeder::class);

        if (! app()->environment('local', 'testing')) {
            return;
        }

        // Local demo data — invented companies and people only, never real prospects.
        User::factory()->create([
            'name' => 'Test User',
            'email' => 'test@example.com',
        ]);

        Company::factory()->count(8)->qualified()->create()->each(function (Company $company) {
            $founder = Person::factory()->for($company)->create();
            $email = EmailAddress::factory()->forPerson($founder)->verified()->create([
                'email' => mb_strtolower($founder->first_name).'@'.$company->domain,
            ]);
            $founder->update(['email_id' => $email->id]);

            Signal::factory()->for($company)->create(['employer_name' => $company->name]);
        });

        Company::factory()->count(4)->rejected(RejectReason::NoValidEmail)->create()->each(function (Company $company) {
            $founder = Person::factory()->for($company)->create();
            EmailAddress::factory()->forPerson($founder)->verified(EmailVerificationStatus::Invalid)->create([
                'email' => mb_strtolower($founder->first_name).'@'.$company->domain,
            ]);
        });

        Company::factory()->count(5)->discovered()->create();
    }
}
