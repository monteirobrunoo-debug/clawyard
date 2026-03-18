<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class SetupAdmin extends Command
{
    protected $signature   = 'clawyard:setup-admin
                                {--email=monteiro.brunoo@gmail.com : Admin email}
                                {--name=Bruno Monteiro : Admin name}
                                {--password= : Admin password (prompted if not provided)}';

    protected $description = 'Setup ClawYard admin user and run pending migrations';

    public function handle(): int
    {
        $this->info('🐾 ClawYard — Setup Admin');
        $this->line('');

        // 1. Check columns exist
        if (!Schema::hasColumn('users', 'role')) {
            $this->warn('Column "role" not found — run: php artisan migrate --force');
            return 1;
        }

        $email = $this->option('email');
        $name  = $this->option('name');

        // 2. Get or create user
        $user = User::where('email', $email)->first();

        if ($user) {
            $this->line("Found user: {$user->email}");

            // Update role to admin and activate
            $user->update([
                'role'      => 'admin',
                'is_active' => true,
            ]);

            // Optional: update password
            if ($this->option('password')) {
                $user->update(['password' => Hash::make($this->option('password'))]);
                $this->line('Password updated.');
            }

            $this->info("✅ {$user->name} is now ADMIN!");

        } else {
            // Create new admin
            $password = $this->option('password') ?: $this->secret("Password para {$email}");

            if (!$password) {
                $this->error('Password nao pode estar vazia.');
                return 1;
            }

            $user = User::create([
                'name'              => $name,
                'email'             => $email,
                'password'          => Hash::make($password),
                'role'              => 'admin',
                'is_active'         => true,
                'email_verified_at' => now(),
            ]);

            $this->info("✅ Admin criado: {$user->email}");
        }

        // 3. Fix all existing users without a role
        $fixed = User::whereNull('role')->orWhere('role', '')->update([
            'role'      => 'user',
            'is_active' => true,
        ]);

        if ($fixed > 0) {
            $this->line("Fixed {$fixed} users without role → set to 'user'");
        }

        $this->line('');
        $this->info('Done! Login com: ' . $email);

        return 0;
    }
}
