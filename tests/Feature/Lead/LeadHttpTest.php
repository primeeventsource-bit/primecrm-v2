<?php

declare(strict_types=1);

use App\Modules\Lead\Domain\Models\Lead;
use App\Support\Enums\UserRole;
use Database\Factories\LeadFactory;

beforeEach(function () {
    $this->actingAsUser(role: UserRole::Supervisor);
});

it('creates a lead via POST /api/leads', function () {
    $response = $this->postJson('/api/leads', [
        'first_name' => 'Jane',
        'last_name' => 'Doe',
        'phone' => '(415) 555-9876',
        'email' => 'jane@example.com',
        'source' => 'referral',
        'priority' => 'high',
        'estimated_value' => 7500,
    ]);

    $response->assertStatus(201);
    $response->assertJsonPath('data.first_name', 'Jane');
    $response->assertJsonPath('data.phone', '+14155559876');

    expect(Lead::query()->count())->toBe(1);
    expect(Lead::query()->first()->phone_hash)
        ->toBe(hash('sha256', '+14155559876'));
});

it('rejects leads with an unparseable phone', function () {
    $this->postJson('/api/leads', [
        'phone' => 'totally not a phone',
        'source' => 'referral',
    ])->assertStatus(422);
});

it('returns 200 with was_duplicate=true when posting a duplicate phone', function () {
    LeadFactory::new()->withPhone('+14155557000')->create();

    $response = $this->postJson('/api/leads', [
        'phone' => '+14155557000',
        'source' => 'referral',
    ]);

    $response->assertStatus(200);
    $response->assertJsonPath('meta.was_duplicate', true);
});

it('lists leads for the active tenant', function () {
    LeadFactory::new()->count(3)->create();

    $response = $this->getJson('/api/leads');
    $response->assertOk();
    $response->assertJsonStructure(['data' => [['id', 'phone', 'status']], 'links', 'meta']);
});

it('filters leads by min_score', function () {
    LeadFactory::new()->create(['score' => 100]);
    LeadFactory::new()->create(['score' => 800]);

    $response = $this->getJson('/api/leads?min_score=500');
    $response->assertOk();
    $response->assertJsonCount(1, 'data');
});

it('rejects bulk import for non-supervisor roles', function () {
    $this->actingAsUser(role: UserRole::Agent);

    $this->postJson('/api/leads/import', [
        'file' => \Illuminate\Http\UploadedFile::fake()->createWithContent('x.csv', "phone\n4155551111"),
        'column_mapping' => ['phone' => 'phone'],
        'source' => 'csv_import',
    ])->assertStatus(403);
});
