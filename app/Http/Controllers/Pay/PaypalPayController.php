<?php

namespace App\Http\Controllers\Pay;


use AmrShawky\LaravelCurrency\Facade\Currency;
use App\Exceptions\RuleValidationException;
use App\Http\Controllers\PayController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use PayPal\Api\Amount;
use PayPal\Api\Details;
use PayPal\Api\Item;
use PayPal\Api\ItemList;
use PayPal\Api\Payer;
use PayPal\Api\Payment;
use PayPal\Api\PaymentExecution;
use PayPal\Api\RedirectUrls;
use PayPal\Api\Transaction;
use PayPal\Auth\OAuthTokenCredential;
use PayPal\Exception\PayPalConnectionException;
use PayPal\Rest\ApiContext;

class PaypalPayController extends PayController
{

    const Currency = 'USD'; //货币单位

    public function gateway(string $payway, string $orderSN)
    {
        try {
            // 加载网关
            $this->loadGateWay($orderSN, $payway);
            $paypal = new ApiContext(
                new OAuthTokenCredential(
                    $this->payGateway->merchant_key,
                    $this->payGateway->merchant_pem
                )
            );
            $paypal->setConfig(['mode' => 'live']);
            $product = $this->order->title;
            // 得到汇率
            $total = Currency::convert()
                ->from('CNY')
                ->to('USD')
                ->amount($this->order->actual_price)
                ->round(2)
                ->get();
            $shipping = 0;
            $description = $this->order->title;
            $paypalTotal = (string) $total;
            $context = $this->buildOrderContextToken($this->order, ['paypal_total' => $paypalTotal]);
            $payer = new Payer();
            $payer->setPaymentMethod('paypal');
            $item = new Item();
            $item->setName($product)->setCurrency(self::Currency)->setQuantity(1)->setPrice($total);
            $itemList = new ItemList();
            $itemList->setItems([$item]);
            $details = new Details();
            $details->setShipping($shipping)->setSubtotal($total);
            $amount = new Amount();
            $amount->setCurrency(self::Currency)->setTotal($total)->setDetails($details);
            $transaction = new Transaction();
            $transaction->setAmount($amount)->setItemList($itemList)->setDescription($description)->setInvoiceNumber($this->order->order_sn);
            $redirectUrls = new RedirectUrls();
            $redirectUrls->setReturnUrl(route('paypal-return', [
                'success' => 'ok',
                'orderSN' => $this->order->order_sn,
                'paypal_total' => $paypalTotal,
                'context' => $context,
            ]))->setCancelUrl(route('paypal-return', [
                'success' => 'no',
                'orderSN' => $this->order->order_sn,
                'paypal_total' => $paypalTotal,
                'context' => $context,
            ]));
            $payment = new Payment();
            $payment->setIntent('sale')->setPayer($payer)->setRedirectUrls($redirectUrls)->setTransactions([$transaction]);
            $payment->create($paypal);
            $approvalUrl = $payment->getApprovalLink();
            return redirect($approvalUrl);
        } catch (PayPalConnectionException $payPalConnectionException) {
            return $this->err($payPalConnectionException->getMessage());
        } catch (RuleValidationException $exception) {
            return $this->err($exception->getMessage());
        }
    }

    /**
     *paypal 同步回调
     */
    public function returnUrl(Request $request)
    {
        $success = $request->input('success');
        $paymentId =  $request->input('paymentId');
        $payerID =  $request->input('PayerID');
        $orderSN = $request->input('orderSN');
        if ($success == 'no' || empty($paymentId) || empty($payerID)) {
            // 取消支付
            if (!empty($orderSN)) {
                return redirect(url('detail-order-sn', ['orderSN' => $orderSN]));
            }
            return 'error';
        }
        $order = $this->orderService->detailOrderSN($orderSN);
        if (!$order) {
            return 'error';
        }
        $paypalTotal = (string) $request->input('paypal_total', '');
        $hasValidContext = $this->validateOrderContextToken($order, $request->input('context'), ['paypal_total' => $paypalTotal]);
        $payGateway = $this->payService->detail($order->pay_id);
        if (!$payGateway) {
            return 'error';
        }
        if (!$this->isExpectedGatewayRoute($payGateway->pay_handleroute, '/pay/paypal')) {
            return 'error';
        }
        $paypal = new ApiContext(
            new OAuthTokenCredential(
                $payGateway->merchant_key,
                $payGateway->merchant_pem
            )
        );
        $paypal->setConfig(['mode' => 'live']);
        $payment = Payment::get($paymentId, $paypal);
        $execute = new PaymentExecution();
        $execute->setPayerId($payerID);
        try {
            $executedPayment = $payment->execute($execute, $paypal);
            if ($executedPayment instanceof Payment) {
                $payment = $executedPayment;
            }
            if ($payment->getState() !== 'approved') {
                throw new \Exception('paypal state invalid');
            }
            $transactions = $payment->getTransactions();
            if (empty($transactions)) {
                throw new \Exception('paypal transaction missing');
            }
            $transaction = $transactions[0];
            $paymentAmount = $transaction->getAmount();
            $expectedPaypalTotal = $hasValidContext
                ? $paypalTotal
                : (string) Currency::convert()
                    ->from('CNY')
                    ->to('USD')
                    ->amount($order->actual_price)
                    ->round(2)
                    ->get();
            if (
                $transaction->getInvoiceNumber() !== $orderSN ||
                $paymentAmount->getCurrency() !== self::Currency ||
                bccomp((string) $paymentAmount->getTotal(), $expectedPaypalTotal, 2) !== 0
            ) {
                throw new \Exception('paypal payment mismatch');
            }
            $this->orderProcessService->completedOrder($orderSN, $order->actual_price, $paymentId);
            Log::info("paypal支付成功",  ['支付成功，支付ID【' . $paymentId . '】,支付人ID【' . $payerID . '】']);
        } catch (\Exception $e) {
            Log::error("paypal支付失败", ['支付失败，支付ID【' . $paymentId . '】,支付人ID【' . $payerID . '】']);
        }
        return redirect(url('detail-order-sn', ['orderSN' => $orderSN]));
    }


    /**
     * 异步通知
     * TODO: 暂未实现，但是好像只实现同步回调即可。这个可以放在后面实现
     */
    public function notifyUrl(Request $request)
    {
        //获取回调结果
        $json_data = $this->get_JsonData();
        if(!empty($json_data)){
            Log::debug("paypal notify info:\r\n" . json_encode($json_data));
        }else{
            Log::debug("paypal notify fail:参加为空");
        }

    }

    private function get_JsonData()
    {
        $json = file_get_contents('php://input');
        if ($json) {
            $json = str_replace("'", '', $json);
            $json = json_decode($json,true);
        }
        return $json;
    }

}
