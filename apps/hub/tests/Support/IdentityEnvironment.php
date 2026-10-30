<?php

namespace Tests\Support;

use Illuminate\Contracts\Console\Kernel;

trait IdentityEnvironment
{
    public function createApplication()
    {
        $directory = sys_get_temp_dir().'/nebuless-identity-tests-'.getmypid();
        if (! is_dir($directory)) {
            mkdir($directory, 0700, true);
        }
        if (! is_file($directory.'/.env')) {
            file_put_contents($directory.'/.env', '');
        }
        $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
        $app->useEnvironmentPath($directory);
        $this->traitsUsedByTest = class_uses_recursive(static::class);
        $app->make(Kernel::class)->bootstrap();

        return $app;
    }
}
