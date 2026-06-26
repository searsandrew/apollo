<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RolesAndPermissionsSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $permissions = array_unique(
            array_merge(...array_values($this->permissions()))
        );

        foreach($permissions as $permission)
        {
            Permission::updateOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $roles = array_keys($this->permissions());

        foreach($roles as $role)
        {
            $setRole = Role::query()->firstOrCreate([
                'name' => $role,
                'guard_name' => 'web',
            ]);

            $setRole->syncPermissions($this->permissions()[$role]);
        }
    }

    private function permissions(): array
    {
        // api, company, customer, dashboard, flier, file, instruction, inventory, invoice, item, landing, lead, masquerade, msrp, order, page, permission, price, rebate, report, setting, user
        return [
            'admin' => ['view api', 'create api', 'delete api', 'view company', 'edit company', 'view customer', 'create customer', 'edit customer', 'delete customer', 'view dashboard', 'view flier', 'create flier', 'edit flier', 'delete flier', 'view file', 'create file', 'delete file', 'view instruction', 'create instruction', 'edit instruction', 'delete instruction', 'view inventory', 'view invoice', 'view item', 'view landing', 'create landing', 'edit landing', 'delete landing', 'view lead', 'edit lead', 'delete lead', 'view masquerade', 'view msrp', 'create msrp', 'edit msrp', 'delete msrp', 'view order', 'create order', 'edit order', 'delete order', 'view page', 'create page', 'edit page', 'delete page', 'view permission', 'edit permission', 'delete permission', 'view price', 'view rebate', 'view report', 'create report', 'edit report', 'delete report', 'view setting', 'edit setting', 'view user', 'create user', 'edit user', 'delete user'],
            'regionalManager' => ['view api', 'create api', 'delete api', 'view company', 'edit company', 'view customer', 'create customer', 'edit customer', 'delete customer', 'view dashboard', 'view flier', 'create flier', 'edit flier', 'delete flier', 'view file', 'create file', 'delete file', 'view instruction', 'view inventory', 'view invoice', 'view item', 'view landing', 'create landing', 'edit landing', 'delete landing', 'view lead', 'edit lead', 'delete lead', 'view masquerade', 'view msrp', 'create msrp', 'edit msrp', 'delete msrp', 'view order', 'create order', 'edit order', 'delete order', 'view page', 'view price', 'view permission', 'view rebate', 'view report', 'create report', 'edit report', 'delete report', 'view setting', 'view user', 'create user', 'edit user'],
            'salesRep' => ['view api', 'create api', 'delete api', 'view company', 'view customer', 'create customer', 'edit customer', 'delete customer', 'view dashboard', 'view flier', 'create flier', 'edit flier', 'delete flier', 'view file', 'create file', 'delete file', 'view instruction', 'view inventory', 'view invoice', 'view item', 'view landing', 'create landing', 'edit landing', 'delete landing', 'view lead', 'view msrp', 'create msrp', 'edit msrp', 'delete msrp', 'view order', 'create order', 'edit order', 'delete order', 'view page', 'view price', 'view rebate', 'view report', 'create report', 'edit report', 'delete report', 'view user', 'edit user'],
            'customerService' => ['view api', 'create api', 'delete api', 'view company', 'view customer', 'create customer', 'edit customer', 'delete customer', 'view dashboard', 'view flier', 'create flier', 'edit flier', 'delete flier', 'view file', 'create file', 'delete file', 'view instruction', 'edit instruction', 'view inventory', 'view invoice', 'view item', 'view landing', 'create landing', 'edit landing', 'delete landing', 'view msrp', 'create msrp', 'edit msrp', 'delete msrp', 'view order', 'create order', 'edit order', 'delete order', 'view page', 'edit page', 'view price', 'view rebate', 'view report', 'create report', 'edit report', 'delete report', 'view user', 'create user', 'edit user', 'delete user'],
            'owner' => ['view api', 'create api', 'delete api', 'view customer', 'create customer', 'edit customer', 'delete customer', 'view dashboard', 'view flier', 'create flier', 'edit flier', 'delete flier', 'view file', 'create file', 'view instruction', 'view inventory', 'view invoice', 'view item', 'view landing', 'create landing', 'edit landing', 'delete landing', 'view msrp', 'create msrp', 'edit msrp', 'delete msrp', 'view order', 'create order', 'edit order', 'delete order', 'view page', 'view price', 'view rebate'],
            'accounting' => ['view customer', 'create customer', 'edit customer', 'delete customer', 'view dashboard', 'view instruction', 'view inventory', 'view invoice', 'view item', 'view msrp', 'view order', 'create order', 'edit order', 'view page', 'view price', 'view rebate'],
            'employee' => ['view customer', 'edit customer', 'view dashboard', 'view file', 'view instruction', 'view inventory', 'view item', 'view msrp', 'view order', 'create order', 'edit order', 'view page', 'view price'],
            'customer' => ['view item', 'view instruction', 'view landing', 'view msrp'],
        ];
    }
}
