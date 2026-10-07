<?php

declare(strict_types=1);

use App\Domain\Sync\SyncOutcome;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\Route as Router;
use Symfony\Component\Yaml\Yaml;

/** The published contract must describe exactly the API that exists. */
it('documents every /api/v1 route and nothing else', function () {
    $spec = Yaml::parseFile(base_path('../docs/api/openapi.yaml'));

    $documented = [];
    foreach ($spec['paths'] as $path => $operations) {
        foreach (array_keys($operations) as $method) {
            $documented[] = strtoupper($method).' /api/v1'.preg_replace('/\{\w+\}/', '{}', $path);
        }
    }

    $actual = collect(Router::getRoutes()->getRoutes())
        ->filter(fn (Route $r) => str_starts_with($r->uri(), 'api/v1/'))
        ->flatMap(fn (Route $r) => collect($r->methods())->reject(fn ($m) => $m === 'HEAD')
            ->map(fn ($m) => $m.' /'.preg_replace('/\{\w+\}/', '{}', $r->uri())))
        ->values()->all();

    sort($documented);
    sort($actual);

    expect($documented)->toBe($actual);
});

it('lists every sync status the server can return', function () {
    $spec = Yaml::parseFile(base_path('../docs/api/openapi.yaml'));
    $statuses = $spec['components']['schemas']['SyncResult']['properties']['status']['enum'];

    $reflection = new ReflectionClass(SyncOutcome::class);
    expect($statuses)->toEqualCanonicalizing(array_values($reflection->getConstants()));
});
