<?php

namespace Modules\Login\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

class CreateUserCms extends Command
{
    protected $signature = 'login:create-user';

    protected $description = 'Create a CMS admin user (Login)';

    public function handle(): int
    {
        $email = trim((string) $this->ask('Enter email login'));
        $password = trim((string) $this->secret('Enter password'));

        $this->info("Email: $email");
        if (! $this->confirm('Are you sure?')) {
            return self::SUCCESS;
        }

        $model = config('login.web.user_model') ?: config('auth.providers.users.model');
        $user = new $model;
        $user->name = $email;
        $user->email = $email;
        $user->login_name = $email;
        $user->password = Hash::make($password);
        $user->email_verified_at = now();
        $user->role = config('login.web.admin_role');
        $user->save();
        $this->info('Create user success!');

        return self::SUCCESS;
    }
}
