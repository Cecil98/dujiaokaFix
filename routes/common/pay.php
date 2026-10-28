<?php
/**
 * The file was created by Assimon.
 *
 * @author    assimon<ashang@utf8.hk>
 * @copyright assimon<ashang@utf8.hk>
 * @link      http://utf8.hk/
 */
use Illuminate\Support\Facades\Route;

Route::get('pay-gateway/{payway}/{orderSN}', 'PayController@redirectGateway');
Route::get('pay-gateway/{handle}/{payway}/{orderSN}', 'PayController@redirectGatewayLegacy');

// 支付相关
Route::group(['prefix' => 'pay', 'namespace' => 'Pay', 'middleware' => ['dujiaoka.pay_gate_way']], function () {
    // 码支付
    Route::get('mapay/{payway}/{orderSN}', 'MapayController@gateway');
    Route::post('mapay/notify_url', 'MapayController@notifyUrl');
    // Paysapi
    Route::get('paysapi/{payway}/{orderSN}', 'PaysapiController@gateway');
    Route::post('paysapi/notify_url', 'PaysapiController@notifyUrl');
    Route::get('paysapi/return_url', 'PaysapiController@returnUrl')->name('paysapi-return');
    // payjs
    Route::get('payjs/{payway}/{orderSN}', 'PayjsController@gateway');
    Route::post('payjs/notify_url', 'PayjsController@notifyUrl');
    // 易支付
    Route::get('yipay/{payway}/{orderSN}', 'YipayController@gateway');
    Route::get('yipay/notify_url', 'YipayController@notifyUrl')->middleware('throttle:120,1');
    Route::get('yipay/return_url', 'YipayController@returnUrl')->middleware('throttle:30,1')->name('yipay-return');
    // V免签
    Route::get('vpay/{payway}/{orderSN}', 'VpayController@gateway');
    Route::get('vpay/notify_url', 'VpayController@notifyUrl');
    Route::get('vpay/return_url', 'VpayController@returnUrl')->name('vpay-return');
    // stripe
    Route::get('stripe/{payway}/{oid}','StripeController@gateway')->middleware('web');
    Route::get('stripe/return_url','StripeController@returnUrl')->middleware(['web', 'throttle:30,1']);
    Route::post('stripe/check','StripeController@check')->middleware(['web', 'throttle:30,1']);
    Route::post('stripe/charge','StripeController@charge')->middleware(['web', 'throttle:30,1']);
    // Coinbase
    Route::get('coinbase/{payway}/{orderSN}', 'CoinbaseController@gateway');
    Route::post('coinbase/notify_url', 'CoinbaseController@notifyUrl');
    // epusdt
    Route::get('epusdt/{payway}/{orderSN}', 'EpusdtController@gateway');
    Route::post('epusdt/notify_url', 'EpusdtController@notifyUrl');
    Route::get('epusdt/return_url', 'EpusdtController@returnUrl')->name('epusdt-return');
    // tokenpay
    Route::get('tokenpay/{payway}/{orderSN}', 'TokenPayController@gateway');
    Route::post('tokenpay/notify_url', 'TokenPayController@notifyUrl');
    Route::get('tokenpay/return_url', 'TokenPayController@returnUrl')->name('tokenpay-return');

});
