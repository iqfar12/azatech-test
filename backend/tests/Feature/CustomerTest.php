<?php

declare(strict_types=1);

use App\Models\Customer;

$valid = [
    'customer_name' => 'Budi Santoso',
    'customer_address' => 'Jakarta',
    'gender' => 'Pria',
    'birth_date' => '1995-02-10',
    'phone' => '0812-3456-7890',
    'email' => 'Budi@Example.com',
];

it('lets a cashier create, update and read customers', function () use ($valid) {
    actingAsRole('cashier');

    $id = $this->postJson('/api/customers', $valid)
        ->assertCreated()
        ->assertJsonPath('data.customer_name', 'Budi Santoso')
        ->assertJsonPath('data.phone', '081234567890')
        ->assertJsonPath('data.email', 'budi@example.com')
        ->json('data.id');

    expect($id)->toBeString()->and(strlen($id))->toBe(36);

    $this->putJson("/api/customers/{$id}", ['customer_address' => 'Bandung'])
        ->assertOk()
        ->assertJsonPath('data.customer_address', 'Bandung')
        ->assertJsonPath('data.customer_name', 'Budi Santoso');

    $this->getJson("/api/customers/{$id}")->assertOk()->assertJsonPath('data.gender', 'Pria');
});

it('validates customer input', function () {
    actingAsRole('cashier');

    $this->postJson('/api/customers', [
        'customer_name' => '',
        'gender' => 'Other',
        'birth_date' => '2999-01-01',
        'phone' => 'abc',
        'email' => 'nope',
    ])->assertStatus(422)
        ->assertJsonValidationErrors(['customer_name', 'customer_address', 'gender', 'birth_date', 'phone', 'email']);
});

it('forbids a cashier from deleting customers', function () {
    actingAsRole('cashier');
    $customer = Customer::factory()->create();

    $this->deleteJson("/api/customers/{$customer->id}")->assertForbidden();
    expect(Customer::find($customer->id))->not->toBeNull();
});

it('lets an admin soft delete a customer', function () {
    actingAsRole('admin');
    $customer = Customer::factory()->create();

    $this->deleteJson("/api/customers/{$customer->id}")->assertNoContent();

    expect(Customer::find($customer->id))->toBeNull()
        ->and(Customer::withTrashed()->find($customer->id))->not->toBeNull();
});

it('paginates, searches and sorts customers', function () {
    actingAsRole('cashier');
    Customer::factory()->create(['customer_name' => 'Zzqx Unik']);
    Customer::factory()->create(['customer_name' => 'Ani']);
    Customer::factory()->count(3)->create();

    $this->getJson('/api/customers?search=zzqx')->assertJsonCount(1, 'data');
    $this->getJson('/api/customers?sort=-customer_name&per_page=1')
        ->assertJsonPath('meta.total', 5)
        ->assertJsonPath('meta.total_pages', 5);
});

it('returns 404 for a malformed uuid instead of a database error', function () {
    actingAsRole('cashier');

    $this->getJson('/api/customers/not-a-uuid')->assertStatus(404);
});
