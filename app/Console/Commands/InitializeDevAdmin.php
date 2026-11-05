<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;

class InitializeDevAdmin extends Command
{
    protected $signature = 'kfos:dev-admin:init';

    protected $description =
        'Initialize the local-development-only K-FOS test administrator';

    public function handle(): int
    {
        // Never initialize a shared administrator outside
        // a deliberately enabled local development environment.
        if (
            ! app()->environment('local') ||
            config('app.debug') !== true ||
            ! in_array(
                config('app.url'),
                ['http://localhost:8080', 'http://127.0.0.1:8080'],
                true
            ) ||
            config('kfos.allow_shared_dev_admin') !== true
        ) {
            $this->error(
                'Shared development administrator initialization is disabled.'
            );

            return self::FAILURE;
        }

        if (User::query()->exists()) {
            $this->error(
                'User accounts already exist. Initialization refused.'
            );

            return self::FAILURE;
        }

        $password = $this->secret(
            'Enter development administrator password'
        );

        if (! is_string($password) || strlen($password) < 16) {
            $this->error('Use a password of at least 16 characters.');

            return self::FAILURE;
        }

        $confirmation = $this->secret(
            'Confirm development administrator password'
        );

        if ($password !== $confirmation) {
            $this->error('Passwords do not match.');

            return self::FAILURE;
        }

        try {
            DB::transaction(function () use ($password) {
                // Serialize competing initializer attempts.
                DB::statement('LOCK TABLE users IN EXCLUSIVE MODE');

                if (User::query()->exists()) {
                    throw new RuntimeException(
                        'User accounts already exist.'
                    );
                }

                $user = new User();

                $user->forceFill([
                    'name' => 'KFOS Development Administrator',
                    'email' => null,
                    'employee_id' => null,
                    'login_id' => 'KFOS_ADMIN',
                    'password' => Hash::make($password),
                    'is_active' => true,
                    'is_system_admin' => true,
                    'is_development_only' => true,
                    'session_version' => 1,
                    'remember_token' => null,
                ]);

                $user->save();

                DB::table('security_audit_logs')->insert([
                    'actor_user_id' => $user->id,
                    'target_user_id' => $user->id,
                    'event_type' => 'development_admin_initialized',
                    'result' => 'success',
                    'details' => json_encode([
                        'account_type' => 'shared_development',
                        'individual_operator_attribution' => false,
                    ]),
                    'is_shared_dev_identity' => true,
                    'created_at' => now(),
                ]);
            });
        } catch (\Throwable $exception) {
            $this->error(
                'Initialization failed. Check the application logs.'
            );

            return self::FAILURE;
        }

        $this->info(
            'KFOS_ADMIN development account initialized successfully.'
        );

        return self::SUCCESS;
    }
}
