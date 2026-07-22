<?php

namespace App\Http\Controllers;

use App\Services\Orders\OrderDraftCreator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OrderController extends Controller
{
    public function create(Request $request, OrderDraftCreator $orders): RedirectResponse
    {
        $order = $orders->createForSelectedCompany($request->user());

        return to_route('order.show', $order);
    }
}
