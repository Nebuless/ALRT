<?php

namespace Tests\Support;

use App\Models\User;
use Illuminate\Support\Facades\Http;
use Symfony\Component\Process\Process;

trait BetterAuthService
{
    private ?Process $authProcess = null;

    public function startBetterAuth(): void
    {
        if ($this->authProcess !== null) {
            return;
        }
        $directory = sys_get_temp_dir().'/nebuless-auth-php-'.bin2hex(random_bytes(12));
        mkdir($directory, 0700);
        $configuration = ['database' => $directory.'/auth.sqlite', 'secret' => bin2hex(random_bytes(48)), 'origin' => 'https://hub.example.test', 'bridge_key' => bin2hex(random_bytes(32)), 'node' => 'node'];
        config(['better-auth' => $configuration]);
        $this->authProcess = new Process(['node', dirname(__DIR__, 3).'/auth/src/server.mjs'], env: [
            'AUTH_DATABASE' => $configuration['database'], 'AUTH_SECRET' => $configuration['secret'], 'AUTH_ORIGIN' => $configuration['origin'],
            'AUTH_BRIDGE_KEY' => $configuration['bridge_key'], 'AUTH_PORT' => '0',
        ]);
        $this->authProcess->setTimeout(30);
        $this->authProcess->start();
        $this->authProcess->waitUntil(fn (string $type, string $output) => str_contains($output, '"ready":true'));
        $ready = json_decode(trim($this->authProcess->getOutput()), true, flags: JSON_THROW_ON_ERROR);
        $url = 'http://127.0.0.1:'.$ready['port'];
        config(['better-auth.bridge_url' => $url]);
        Http::allowStrayRequests([$url.'/*']);
        $process = $this->authProcess;
        $this->beforeApplicationDestroyed(function () use ($process, $directory): void {
            $process->stop(1);
            foreach (glob($directory.'/*') as $file) {
                unlink($file);
            }
            rmdir($directory);
        });
    }

    public function provisionBetterOwner(string $email = 'owner@example.test', bool $recover = false, string $password = 'correct-password'): User
    {
        $this->startBetterAuth();
        $this->artisan('fleet:provision-owner', ['email' => $email, '--recover' => $recover])
            ->expectsQuestion('New owner password (at least 12 characters)', $password)
            ->expectsQuestion('Confirm new owner password', $password)->assertSuccessful();

        return User::firstOrFail();
    }

    public function signInBetterOwner(?User $owner = null): array
    {
        $owner ??= $this->provisionBetterOwner();
        $this->post('/login', ['email' => $owner->email, 'password' => 'correct-password'])->assertRedirect('/owner/enroll');
        $this->post('/owner/enroll', ['password' => 'correct-password'])->assertRedirect('/owner/enroll');
        $enrollment = session('owner.enrollment');
        $this->post('/owner/enroll/confirm', ['code' => $this->betterTotp($enrollment['totpURI'])])->assertRedirect('/owner/enroll');
        $this->get('/owner/enroll')->assertOk()->assertSee('Recovery codes');

        $this->get('/')->assertOk();

        return [request()->user(), $enrollment];
    }

    public function betterTotp(string $uri): string
    {
        parse_str(parse_url($uri, PHP_URL_QUERY), $query);
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $bits = '';
        foreach (str_split(strtoupper($query['secret'])) as $character) {
            $bits .= str_pad(decbin(strpos($alphabet, $character)), 5, '0', STR_PAD_LEFT);
        }
        $key = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $key .= chr(bindec($byte));
            }
        }
        $hash = hash_hmac('sha1', pack('J', intdiv(time(), 30)), $key, true);
        $offset = ord(substr($hash, -1)) & 15;
        $value = unpack('N', substr($hash, $offset, 4))[1] & 0x7FFFFFFF;

        return str_pad((string) ($value % 1000000), 6, '0', STR_PAD_LEFT);
    }
}
