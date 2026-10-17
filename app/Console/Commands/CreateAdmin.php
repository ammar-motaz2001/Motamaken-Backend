<?php

namespace App\Console\Commands;

use App\Enums\AuthMethod;
use App\Http\Requests\Auth\IdentifierRequest;
use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

#[Signature('admin:create {email : The admin\'s email address} {--password= : Defaults to a random one, printed once} {--name=Admin : Shown on the account}')]
#[Description('Create an administrator account, or make an existing account an administrator')]
class CreateAdmin extends Command
{
    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $password = $this->option('password') ?: Str::password(16);

        $validator = Validator::make(['email' => $email, 'password' => $password], [
            'email' => ['required', 'email', 'max:255'],
            'password' => IdentifierRequest::passwordRules(),
        ]);
        if ($validator->fails()) {
            $this->error($validator->errors()->first());

            return self::FAILURE;
        }

        $user = User::findByIdentifier(AuthMethod::Email, $email);
        $created = ! $user;

        if (! $user) {
            $name = trim((string) $this->option('name')) ?: 'Admin';
            $user = new User(['first_name' => $name, 'username' => $name, 'password' => $password]);
            $user->forceFill(['email' => $email]);
        } elseif ($this->option('password')) {
            $user->password = $password;
        }

        $user->forceFill(['is_admin' => true, 'suspended_at' => null, 'suspension_reason' => null])->save();
        $user->markVerified(AuthMethod::Email);

        $this->info($created ? "Created the administrator {$email}." : "{$email} is now an administrator.");
        if ($created || $this->option('password')) {
            $this->line("Password: {$password}");
        } else {
            $this->line('Their password is unchanged (pass --password= to set one).');
        }

        return self::SUCCESS;
    }
}
