<?php

namespace App\Http\Controllers;

use App\Http\Requests\OrderRequest;
use App\Models\Order;
use App\Models\ProductSku;
use App\Models\UserAddress;
use Carbon\Carbon;
use App\Exceptions\InvalidRequestException;
use App\Jobs\CloseOrder;

class OrdersController extends Controller
{
    public function store(OrderRequest $request)
    {
        $user = $request->user();

        // 开启一个数据库事务
        $order = \DB::transaction(function () use ($user, $request) {
            $address = UserAddress::find($request->address_id);
            $address->update(['last_used_at' => Carbon::now()]);
            // 创建一个订单
            $order = new Order([
                'address' => [
                    'address' => $address->full_address,
                    'zip' => $address->zip,
                    'contact_name' => $address->contact_name,
                    'contact_phone' => $address->contact_phone,
                ],
                'remark' => $request->remark,
                'total_amount' => 0,
            ]);
            // 关联用户后保存
            $order->user()->associate($user);
            $order->save();

            // 遍历 items 写入 order_items 表
            $total_amount = 0;
            $items = $request->items;
            foreach ($items as $data) {
                $sku = ProductSku::find($data['sku_id']);
                $item = $order->items()->make([
                    'amount' => $data['amount'],
                    'price' => $sku->price,
                ]);
                $item->product()->associate($sku->product_id);
                $item->productSku()->associate($sku);
                $item->save();
                $total_amount += $sku->price * $data['amount'];
                // 减去对应商品的库存量
                if ($sku->decreaseStock($data['amount']) <= 0) {
                    throw new InvalidRequestException('该商品库存不足');
                }
            }

            // 更新订单总金额
            $order->update(['total_amount' => $total_amount]);

            // 将下单商品从购物车中移除
            $sku_ids = collect($items)->pluck('sku_id');
            $user->cartItems()->whereIn('product_sku_id', $sku_ids)->delete();

            return $order;
        });

        $this->dispatch(new CloseOrder($order, config('app.order_ttl')));

        return $order;
    }

    public function index()
    {
        $orders = Order::query()
            ->where('user_id', \Auth::id())
            ->with(['items.product', 'items.productSku'])
            ->latest()
            ->paginate();

        return view('orders.index', compact('orders'));
    }
}
