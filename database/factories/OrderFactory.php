<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'order_sn' => strtoupper(Str::random(12)),
            'goods_id' => rand(1, 3),
            'coupon_id' => rand(1, 3),
            'title' => $this->faker->words(3, true),
            'type' => rand(1, 2),
            'goods_price' => $this->faker->randomFloat(2, 10, 100),
            'buy_amount' => rand(1, 10),
            'coupon_discount_price' => $this->faker->randomFloat(2, 0, 100),
            'wholesale_discount_price' => $this->faker->randomFloat(2, 0, 100),
            'total_price' => $this->faker->randomFloat(2, 10, 100),
            'actual_price' => $this->faker->randomFloat(2, 10, 100),
            'search_pwd' => $this->faker->password(6, 10),
            'email' => $this->faker->email,
            'info' => $this->faker->words(3, true),
            'pay_id' => rand(1, 20),
            'buy_ip' => $this->faker->ipv4,
            'trade_no' => strtoupper(Str::random(12)),
            'status' => rand(1, 5),
            'created_at' => $this->faker->dateTimeBetween('-7 days', 'now', 'PRC'),
            'updated_at' => $this->faker->dateTimeBetween('-7 days', 'now', 'PRC'),
        ];
    }
}
