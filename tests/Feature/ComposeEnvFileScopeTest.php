<?php

use App\Models\Application;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use phpseclib3\Crypt\EC;
use Symfony\Component\Yaml\Yaml;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team);

    $this->project = Project::factory()->create(['team_id' => $this->team->id]);
    $this->environment = Environment::factory()->create(['project_id' => $this->project->id]);

    $ecKey = EC::createKey('Ed25519');

    $privateKey = PrivateKey::create([
        'name' => 'test-key',
        'private_key' => $ecKey->toString('OpenSSH'),
        'team_id' => $this->team->id,
    ]);

    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
        'private_key_id' => $privateKey->id,
    ]);

    ServerSetting::create([
        'server_id' => $this->server->id,
        'wildcard_domain' => 'http://127.0.0.1.sslip.io',
    ]);

    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
        'network' => 'test-network-'.fake()->uuid(),
    ]);
});

test('applicationParser does not inject the shared .env file into compose services', function () {
    $dockerCompose = <<<'YAML'
services:
  app:
    image: myapp/app:latest
    environment:
      - APP_SECRET=${APP_SECRET}
  db:
    image: postgres:16
    environment:
      - POSTGRES_PASSWORD=${POSTGRES_PASSWORD}
YAML;

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $dockerCompose,
        'fqdn' => null,
    ]);

    applicationParser($application);

    $application->refresh();

    $services = data_get(Yaml::parse($application->docker_compose), 'services');

    expect($services)->toHaveKeys(['app', 'db'])
        ->and($services['app'])->not->toHaveKey('env_file')
        ->and($services['db'])->not->toHaveKey('env_file')
        ->and($services['app'])->toHaveKey('environment')
        ->and($services['db'])->toHaveKey('environment');
});

test('applicationParser preserves env_file entries declared by the user', function () {
    $dockerCompose = <<<'YAML'
services:
  app:
    image: myapp/app:latest
    env_file:
      - app.env
  db:
    image: postgres:16
    env_file:
      - .env
YAML;

    $application = Application::factory()->create([
        'environment_id' => $this->environment->id,
        'destination_id' => $this->destination->id,
        'destination_type' => StandaloneDocker::class,
        'build_pack' => 'dockercompose',
        'docker_compose_raw' => $dockerCompose,
        'fqdn' => null,
    ]);

    applicationParser($application);

    $application->refresh();

    $services = data_get(Yaml::parse($application->docker_compose), 'services');

    expect($services['app']['env_file'])->toBe(['app.env'])
        ->and($services['db']['env_file'])->toBe(['.env']);
});

test('serviceParser does not inject the shared .env file into compose services', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:latest
    environment:
      - APP_SECRET=${APP_SECRET}
  redis:
    image: redis:7
YAML,
    ]);

    ServiceApplication::create([
        'name' => 'app',
        'service_id' => $service->id,
        'image' => 'nginx:latest',
    ]);

    $parsedCompose = serviceParser($service);

    expect(data_get($parsedCompose, 'services.app'))->not->toHaveKey('env_file')
        ->and(data_get($parsedCompose, 'services.redis'))->not->toHaveKey('env_file');
});

test('serviceParser preserves env_file entries declared by the user', function () {
    $service = Service::factory()->create([
        'environment_id' => $this->environment->id,
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'docker_compose_raw' => <<<'YAML'
services:
  app:
    image: nginx:latest
    env_file:
      - app.env
YAML,
    ]);

    ServiceApplication::create([
        'name' => 'app',
        'service_id' => $service->id,
        'image' => 'nginx:latest',
    ]);

    $parsedCompose = serviceParser($service);

    expect(data_get($parsedCompose, 'services.app.env_file'))->toBe(['app.env']);
});
