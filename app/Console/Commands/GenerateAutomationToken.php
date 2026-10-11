<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

/**
 * Creates the /api/v1 bearer token and writes it to .env, like key:generate.
 *
 * The token is printed once, for pasting into n8n's credential. It lives only
 * in .env (gitignored) and in n8n — never in Git, docs or chat (plan section 6).
 */
class GenerateAutomationToken extends Command
{
    protected $signature = 'automation:token
        {--force : Replace an existing token (n8n must then be updated)}';

    protected $description = 'Generate the bearer token for the automation API (/api/v1) and store it in .env';

    public function handle(): int
    {
        $path = $this->laravel->environmentFilePath();

        if (! is_file($path) || ! is_writable($path)) {
            $this->components->error("Cannot write {$path}.");

            return self::FAILURE;
        }

        $env = file_get_contents($path);
        $hasLine = preg_match('/^AUTOMATION_API_TOKEN=(.*)$/m', $env, $match) === 1;
        $current = $hasLine ? trim($match[1], " \t\"'") : '';

        if ($current !== '' && ! $this->option('force')) {
            $this->components->warn('A token is already set. Use --force to replace it; n8n will then need the new one.');

            return self::FAILURE;
        }

        // 64 characters from random_bytes (Str::random), letters and digits only: safe in .env and headers.
        $token = Str::random(64);
        $line = 'AUTOMATION_API_TOKEN='.$token;

        $env = $hasLine
            ? preg_replace('/^AUTOMATION_API_TOKEN=.*$/m', $line, $env, 1)
            : rtrim($env, "\n")."\n".$line."\n";

        file_put_contents($path, $env, LOCK_EX);

        $this->components->info('Token written to .env. Paste it into n8n as a Bearer credential — it is shown only now:');
        $this->line($token);
        $this->newLine();
        $this->components->warn('Then run: php artisan config:clear   (config:cache too, if you use it)');

        return self::SUCCESS;
    }
}
