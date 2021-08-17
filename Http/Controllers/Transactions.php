<?php

namespace Modules\Transaction\Http\Controllers;

use Carbon\Carbon;
use Illuminate\Http\Request;
use Shetabit\Multipay\Invoice;
use App\Traits\TransactionActions;
use App\Http\Controllers\Controller;
use Shetabit\Payment\Facade\Payment;
use Modules\Transaction\Models\CoreTransaction;
use Shetabit\Multipay\Exceptions\InvalidPaymentException;
use Shetabit\Multipay\Exceptions\PurchaseFailedException;

class Transactions extends Controller
{

    use TransactionActions;

    /**
     * Create New invoice
     * 
     * @param Illuminate\Http\Request order_id
     * @param Illuminate\Http\Request user_id
     * @param Illuminate\Http\Request amount
     * @param Illuminate\Http\Request desc
     * @return redirect
     */
    public function pay_by_zarinpal(Request $request) {
        try {

            $inv = new Invoice;
            $inv->amount((int)$request->amount);
            $inv->detail([
                'description' => $request->desc
            ]);

            // Purchase the given invoice.
            return Payment::purchase($inv,(
                function($driver, $authority) use ($request) {
                    CoreTransaction::create([
                        'user_id'     => $request->user_id,
                        'amount'      => (int)$request->amount,
                        'authority'   => $authority,
                        'driver'      => 'zarinpal',
                        'description' => $request->desc,
                        'meta'        => [
                            'membership_id' => ($request->has('membership_id') ? $request->membership_id : null),
                            'contract_id'   => ($request->has('contract_id') ? $request->contract_id : null)
                        ]
                    ]);
                }
            ))->pay()->toJson();

        } catch (PurchaseFailedException $exception) {
            return response()->json(['success' => false, 'catch' => $exception->getMessage()]);
        }
    }

    /**
     * Verify Payment
     * 
     * @param Illuminate\Http\Request Authority
     * @param Illuminate\Http\Request Status
     * @return Illuminate\Http\Response
     */
    public function verify_payment(Request $request) {
        try {
            if($request->Status == 'OK') {
                $transaction = CoreTransaction::where('authority', '=', $request->Authority)->first();
                $receipt = Payment::amount($transaction->amount)->transactionId($request->Authority)->verify();

                // Save Refrence Id and verify order record
                $transaction->verified = 1;
                $transaction->refrence_id = $receipt->getReferenceId();
                $transaction->save();

                //** Your Extra Actions ...[Use traits on your app or type codes here]
                if($transaction->description == 'خرید بسته کاربری' || $transaction->description == 'تمدید بسته کاربری') {
                    $this->set_membership($transaction->meta['membership_id']);
                } else if ($transaction->description == 'پرداخت مبلغ ضمانت') {
                    $this->charge_pending_balance($transaction->user_id, $transaction->meta['contract_id'], $transaction->amount);
                }

            
                // You can show payment referenceId to the user.
                return response()->json([
                    'success'   => true,
                    'refs'      => $receipt->getReferenceId(),
                    'cost'      => $transaction->amount,
                ]);
            } else {
                CoreTransaction::where('authority', '=', $request->Authority)->delete();
                return response()->json([
                    'success'   => false,
                    'catch'     => 'تراکنش ناموفق بوده است؛ چنانچه مبلغی از حساب شما کسر شده است، طی 48 ساعت به حساب شما باز خواهد گشت.', 
                ], 500);
            }
        } catch (InvalidPaymentException $exception) {
            CoreTransaction::where('authority', '=', $request->Authority)->delete();
            /**
                when payment is not verified, it will throw an exception.
                We can catch the exception to handle invalid payments.
                getMessage method, returns a suitable message that can be used in user interface.
            **/
            return response()->json(['success' => false, 'catch' => $exception->getMessage()], 500);
        }
    }
}
