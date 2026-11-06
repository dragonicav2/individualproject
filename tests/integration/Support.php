<?php

declare(strict_types=1);

require_once dirname(__DIR__, 2).'/vendor/autoload.php';

if (! isset($GLOBALS['integration_app_booted'])) {
    $app = require dirname(__DIR__, 2).'/bootstrap/app.php';
    $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
    config([
        'database.default' => 'sqlite',
        'database.connections.sqlite.database' => ':memory:',
        'database.connections.sqlite.foreign_key_constraints' => true,
    ]);
    Illuminate\Support\Facades\DB::purge('sqlite');
    Illuminate\Support\Facades\Artisan::call('migrate:fresh', ['--force' => true]);
    $GLOBALS['integration_app_booted'] = true;
}

function integrationCheck(bool $condition, string $message, ?string $passMessage = null): void
{
    if (! $condition) throw new RuntimeException($message);
    echo '    PASS  '.($passMessage ?? $message)."\n";
}

function integrationApi(string $method, string $path, array $body = [], ?string $token = null): array
{
    Illuminate\Support\Facades\Auth::forgetGuards();
    $server = ['HTTP_ACCEPT' => 'application/json', 'CONTENT_TYPE' => 'application/json'];
    if ($token !== null) $server['HTTP_AUTHORIZATION'] = 'Bearer '.$token;
    $request = Illuminate\Http\Request::create(
        '/api/v1'.($path[0] === '/' ? $path : '/'.$path),
        strtoupper($method), [], [], [], $server,
        json_encode($body, JSON_THROW_ON_ERROR),
    );
    $kernel = app(Illuminate\Contracts\Http\Kernel::class);
    $response = $kernel->handle($request);
    $result = ['status' => $response->getStatusCode(), 'json' => json_decode($response->getContent(), true) ?? []];
    $kernel->terminate($request, $response);
    return $result;
}

function integrationUser(string $name, string $email, string $type = 'player'): App\Models\User
{
    return App\Models\User::create([
        'name' => $name,
        'email' => $email,
        'password' => 'CurrentPassword9!',
        'account_type' => $type,
    ]);
}

function runIntegrationScenario(string $title, callable $test): void
{
    Illuminate\Support\Facades\DB::beginTransaction();
    try {
        $test();
        Illuminate\Support\Facades\DB::rollBack();
        echo "PASS  {$title}\n";
    } catch (Throwable $error) {
        Illuminate\Support\Facades\DB::rollBack();
        fwrite(STDERR, "FAIL  {$title}\n{$error->getMessage()}\n");
        throw $error;
    }
}
