<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class PurchaseController extends Controller
{
    public function makeOrder(Request $request): JsonResponse
    {
        $adminFee = 0;
        $discountAmount = 0;
        $validated = $request->validate([
            'item_type' => 'required|string|' . Rule::in([ClassModel::class, User::class]),
            'item_id' => 'required|integer',
            'interval' => 'required|string|' . Rule::in(['monthly', 'annually', 'lifetime']),
            'amount' => 'required|integer',
            'promo_id' => 'nullable|integer|exists:promos,id'
        ]);

        $user = $request->user();

        try {
            $order = Order::create([
                'user_id' => $user->id
            ]);

            $itemModel = null;
            switch ($validated['item_type']) {
                case ClassModel::class:
                    $itemModel = ClassModel::findOrFail($validated['item_id']);
                    break;
                case User::class:
                    $itemModel = User::findOrFail($validated['item_id']);
                    break;

                default:
                    return response()->json(['status' => 'error', 'message' => 'Item type does not match'], 404);
                    break;
            }

            switch ($validated['interval']) {
                case 'monthly':
                    $order->items()->create([
                        'order_id' => $order->id,
                        'item_type' => $itemModel->getMorphClass(),
                        'item_id' => $validated['item_id'],
                        'interval' => $validated['interval'],
                        'amount' => $validated['amount'],
                        'price' => $itemModel->price * $validated['amount']
                    ]);
                    break;

                case 'annually':
                    $price = ($itemModel->price * 12) * 0.9;
                    $order->items()->create([
                        'order_id' => $order->id,
                        'item_type' => $itemModel->getMorphClass(),
                        'item_id' => $validated['item_id'],
                        'interval' => $validated['interval'],
                        'amount' => $validated['amount'],
                        'price' => $price * $validated['amount']
                    ]);

                case 'lifetime':
                    $order->items()->create([
                        'order_id' => $order->id,
                        'item_type' => $itemModel->getMorphClass(),
                        'item_id' => $validated['item_id'],
                        'interval' => $validated['interval'],
                        'amount' => $validated['amount'],
                        'price' => $itemModel->lifetime_price
                    ]);

                default:
                    return response()->json(['status' => 'error', 'message' => 'Interval does not match']);
                    break;
            }

            $setting = CommissionSetting::where('item_type', $validated['item_type'])
                ->where('interval', $validated['interval'])
                ->first();

            if ($setting['is_percentage']) {
                $adminFee = $validated['interval'] === 'lifetime'
                    ? ($order->items->first()->lifetime_price * $setting['platform_share']) / 100
                    : ($order->items->first()->price * $setting['platform_share']) / 100;
            } else {
                $adminFee = $setting['fixed_commission'];
            }

            $finalAmount = $order->items->first()->price - $adminFee;

            if (isset($validated['promo_id'])) {
                $promo = Promo::where('id', $validated['promo_id'])
                    ->where('is_active', true)
                    ->first();

                if ($promo) {
                    if ($promo->discount_type === 'percentage') {
                        $discountAmount = $order->items->first()->price * ($promo->discount_value / 100);
                    }
                    if ($promo->discount_type === 'fixed') {
                        $discountAmount = $promo->discount_value;
                    }
                }
            }
            $finalAmount -= $discountAmount;

            $order->update([
                'total_amount' => $order->items->first()->price,
                'total_admin_fee' => $adminFee,
                'discount_amount' => $discountAmount,
                'final_amount' => $finalAmount
            ]);

            $payment = new PaymentController();
            $response = $payment->createInvoice($order->id);

            return $response;
        } catch (QueryException $e) {
            Log::error('Database error on order creation:', ['exception' => $e->getMessage()]);
            return response()->json([
                'error' => 'Database error occurred while creating the order',
                'message' => $e->getMessage(),
            ], 500);
        } catch (\Exception $e) {
            Log::error('General error on order creation:', ['exception' => $e->getMessage()]);
            return response()->json([
                'error' => 'An error occurred while creating the order',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function getOrder($order_id): JsonResponse
    {
        $order = Order::with(['items.item', 'promo'])->findOrFail($order_id);

        return response()->json($order);
    }

    public function orderHistory(Request $request): JsonResponse
    {
        $user = $request->user();
        $orders = Order::with(['items.item', 'promo'])
            ->where('user_id', $user->id)
            ->orderBy('created_at', 'desc')
            ->paginate(15);

        return response()->json($orders);
    }
}
