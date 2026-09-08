<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\Product;
use App\Models\Transaction;
use Illuminate\Support\Facades\DB;

class TransactionProcessor
{
    /**
     * Apply transaction effects to stock and customer balances upon confirmation.
     */
    public function applyTransaction(Transaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $transaction->load(['items.product', 'customer']);

            // 1. Handle Customer Balance
            if ($transaction->customer_id && $transaction->customer) {
                if ($transaction->type === 'SALE') {
                    $unpaid = $transaction->total_amount - $transaction->paid_amount;
                    if ($unpaid > 0) {
                        $transaction->customer->increment('balance', $unpaid);
                    }
                } elseif ($transaction->type === 'PAYMENT') {
                    $payment = $transaction->paid_amount > 0 ? $transaction->paid_amount : $transaction->total_amount;
                    $newBalance = max(0, $transaction->customer->balance - $payment);
                    $transaction->customer->update(['balance' => $newBalance]);
                }
            }

            // 2. Handle Product Stock Changes
            foreach ($transaction->items as $item) {
                if ($item->product_id && $item->product) {
                    if ($transaction->type === 'SALE') {
                        $newStock = max(0, $item->product->stock - $item->quantity);
                        $item->product->update(['stock' => $newStock]);
                    } elseif ($transaction->type === 'RESTOCK') {
                        $item->product->increment('stock', $item->quantity);
                    }
                }
            }

            $transaction->update(['status' => 'CONFIRMED']);
        });
    }

    /**
     * Reject or cancel a transaction.
     */
    public function rejectTransaction(Transaction $transaction): void
    {
        $transaction->update(['status' => 'REJECTED']);
    }
}
