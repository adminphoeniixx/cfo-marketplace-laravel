<?php

namespace Database\Seeders;

use App\Models\Customer;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Env;
use Illuminate\Support\Str;

/**
 * One shopper account somebody outside the company can sign in with.
 *
 * Both app stores ask for working credentials before they will review a build,
 * and the app's front door is a mobile number and an SMS code — which is no
 * use to a reviewer in another country holding neither the phone nor, until
 * the provider is wired up, any code at all. The app's other door is an email
 * and a password, and this makes an account that fits it.
 *
 * Safe against a live database: one customer row, no orders, no catalog.
 *
 * Supply DEMO_CUSTOMER_EMAIL / DEMO_CUSTOMER_PASSWORD to choose the
 * credentials; without a password one is generated and printed once. Running
 * it again with DEMO_CUSTOMER_PASSWORD set resets the password, which is how
 * you recover the account rather than making a second one.
 */
class DemoCustomerSeeder extends Seeder
{
    use WithoutModelEvents;

    public function run(): void
    {
        $email = (string) Env::get('DEMO_CUSTOMER_EMAIL', 'demo.shopper@marketplace.test');
        $supplied = (string) Env::get('DEMO_CUSTOMER_PASSWORD', '');
        $password = $supplied ?: Str::password(16, symbols: false);

        $customer = Customer::withTrashed()->where('email', $email)->first();

        if ($customer) {
            // A demo account nobody can get into is not a demo account, so a
            // supplied password always wins over whatever is on the row.
            $customer->restore();
            $customer->fill([
                'status' => 'active',
                ...($supplied ? ['password' => $supplied] : []),
            ])->save();

            $this->command->warn("Demo shopper [{$email}] already existed.");

            if (! $supplied) {
                $this->command->line('Its password was left alone — pass DEMO_CUSTOMER_PASSWORD to reset it.');

                return;
            }
        } else {
            $customer = Customer::create([
                'first_name' => (string) Env::get('DEMO_CUSTOMER_NAME', 'Demo'),
                'last_name' => 'Shopper',
                'email' => $email,
                'password' => $password,
                'phone' => Env::get('DEMO_CUSTOMER_PHONE') ?: null,
                'status' => 'active',
                'email_verified' => true,
                'accepts_marketing' => false,
                // So the next person through the customers list knows why a
                // shopper with no orders is sitting there.
                'tags' => ['demo'],
                'notes' => 'Review account for the app stores. Not a real shopper — leave it be.',
            ]);
        }

        $this->command->newLine();
        $this->command->info('Demo shopper ready. Hand these to the app store reviewer:');
        $this->command->line("Email: {$email}");
        $this->command->line("Password: {$password}");

        if ($customer->phone) {
            $this->command->line("Phone: {$customer->phone}");
        }

        $this->command->newLine();
        $this->command->line('In the app, take the "Sign in with email" door. The mobile-number');
        $this->command->line('one needs an SMS provider and will not reach a reviewer.');
    }
}
