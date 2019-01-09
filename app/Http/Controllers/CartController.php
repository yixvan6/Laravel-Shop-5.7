<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Http\Requests\AddCartRequest;
use App\Models\CartItem;

class CartController extends Controller
{
    public function add(AddCartRequest $request)
    {
        $user = $request->user();
        $sku_id = $request->sku_id;
        $amount = $request->amount;

        // 检查购物车中是否已存在该商品，若存在只增加数量
        if ($cart = $user->cartItems()->where('product_sku_id', $sku_id)->first()) {
            $cart->update(['amount' => $cart->amount + $amount]);
        } else {
            $cart = new CartItem(['amount' => $amount]);
            $cart->user()->associate($user);
            $cart->productSku()->associate($sku_id);
            $cart->save();
        }

        return [];
    }
}
