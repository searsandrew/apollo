<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\User;
use Illuminate\Validation\ValidationException;

class OrderDraftCreator
{
    public function createForSelectedCompany(User $user): Order
    {
        $netsuiteCompanyId = (int) $user->getMeta('company_id', 0);

        if ($netsuiteCompanyId <= 0) {
            throw ValidationException::withMessages([
                'company' => __('Select a company before creating an order.'),
            ]);
        }

        return Order::query()->create([
            'netsuite_company_id' => $netsuiteCompanyId,
            'created_by_user_id' => $user->id,
            'status' => Order::STATUS_DRAFT,
            'origin' => 'web',
        ]);
    }
}
