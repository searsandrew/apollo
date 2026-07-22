<?php

use App\Models\CompanySummary;
use App\Models\Order;
use App\Models\OrderLine;
use App\Models\User;
use Livewire\Livewire;
use OwenIt\Auditing\Models\Audit;
use Spatie\Permission\Models\Permission;

beforeEach(function (): void {
    config(['audit.console' => true]);

    Permission::findOrCreate('view order');
    Permission::findOrCreate('create order');
});

it('creates a draft order for the selected company and opens it', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['view order', 'create order']);
    $user->setMeta('company_id', 286);

    CompanySummary::factory()->create([
        'netsuite_company_id' => 286,
        'company_name' => 'Acme Industrial',
    ]);

    $response = $this->actingAs($user)->post(route('order.create'));

    $order = Order::query()->firstOrFail();

    $response->assertRedirect(route('order.show', $order));

    expect($order)
        ->netsuite_company_id->toBe(286)
        ->created_by_user_id->toBe($user->id)
        ->status->toBe(Order::STATUS_DRAFT)
        ->uuid->not->toBeNull();

    expect(Audit::query()->where('auditable_type', Order::class)->where('auditable_id', $order->id)->count())
        ->toBe(1);
});

it('requires a selected company before starting an order', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['view order', 'create order']);

    $this->actingAs($user)
        ->from(route('order.index'))
        ->post(route('order.create'))
        ->assertRedirect(route('order.index'))
        ->assertSessionHasErrors('company');
});

it('lists orders for the selected company with line counts', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('view order');
    $user->setMeta('company_id', 286);

    CompanySummary::factory()->create([
        'netsuite_company_id' => 286,
        'company_name' => 'Acme Industrial',
    ]);

    $order = Order::factory()->for($user, 'creator')->create([
        'netsuite_company_id' => 286,
        'po_number' => 'PO-1001',
    ]);

    Order::factory()->for($user, 'creator')->create([
        'netsuite_company_id' => 999,
        'po_number' => 'PO-OTHER',
    ]);

    OrderLine::factory()->count(2)->for($order)->create();

    Livewire::actingAs($user)
        ->test('pages::order.index')
        ->assertSee('Acme Industrial')
        ->assertSee('PO-1001')
        ->assertSee('2')
        ->assertDontSee('PO-OTHER');
});

it('renders the order index and draft order routes', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo('view order');
    $user->setMeta('company_id', 286);

    $order = Order::factory()->for($user, 'creator')->create([
        'netsuite_company_id' => 286,
    ]);

    $this->actingAs($user)
        ->get(route('order.index'))
        ->assertOk()
        ->assertSee('Orders');

    $this->actingAs($user)
        ->get(route('order.show', $order))
        ->assertOk()
        ->assertSee('Order #'.$order->id);
});

it('autosaves draft order fields and line items', function (): void {
    $user = User::factory()->create();
    $user->givePermissionTo(['view order', 'create order']);

    $order = Order::factory()->for($user, 'creator')->create([
        'netsuite_company_id' => 286,
    ]);

    Livewire::actingAs($user)
        ->test('pages::order.show', ['order' => $order])
        ->set('poNumber', 'PO-1001')
        ->set('remarks', 'Deliver to side door')
        ->set('partNumber', 'WB31X5013CM')
        ->set('quantity', 4)
        ->call('addLine')
        ->assertSet('partNumber', '')
        ->assertSet('quantity', 1)
        ->assertSee('WB31X5013CM')
        ->assertSee('Pending lookup');

    $order->refresh();

    expect($order)
        ->po_number->toBe('PO-1001')
        ->remarks->toBe('Deliver to side door');

    expect(OrderLine::query()->first())
        ->part_number->toBe('WB31X5013CM')
        ->quantity->toBe(4);

    expect(Audit::query()->where('auditable_type', Order::class)->where('auditable_id', $order->id)->count())
        ->toBe(3);

    expect(Audit::query()->where('auditable_type', OrderLine::class)->count())
        ->toBe(1);
});

it('prevents users from opening another users order', function (): void {
    $owner = User::factory()->create();
    $visitor = User::factory()->create();
    $visitor->givePermissionTo('view order');

    $order = Order::factory()->for($owner, 'creator')->create();

    Livewire::actingAs($visitor)
        ->test('pages::order.show', ['order' => $order])
        ->assertForbidden();
});
