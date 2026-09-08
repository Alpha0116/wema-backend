<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags 1. Tableau de bord (Accueil)
 */
class DashboardController extends Controller
{
    /**
     * Obtenir la vue d'ensemble, KPIs et flux d'activité du tableau de bord.
     * 
     * Retourne les 4 indicateurs clés du jour (Chiffre d'affaires, Montant encaissé, Créances totales en cours, Transactions en attente), le bandeau d'alertes urgentes (transactions à valider, alertes de stock), ainsi que le flux des 10 dernières activités récentes.
     */
    public function overview(Request $request): JsonResponse
    {
        $merchant = Merchant::first();
        if (!$merchant) {
            return response()->json(['message' => 'Aucun commerce trouvé'], 404);
        }

        $today = Carbon::today();

        // 1. Indicateurs clés (Bandeau supérieur)
        $dailySales = (int) Transaction::where('merchant_id', $merchant->id)
            ->where('type', 'SALE')
            ->where('status', 'CONFIRMED')
            ->whereDate('created_at', $today)
            ->sum('total_amount');

        $dailyCollectedSales = (int) Transaction::where('merchant_id', $merchant->id)
            ->where('type', 'SALE')
            ->where('status', 'CONFIRMED')
            ->whereDate('created_at', $today)
            ->sum('paid_amount');

        $dailyPaymentsReceived = (int) Transaction::where('merchant_id', $merchant->id)
            ->where('type', 'PAYMENT')
            ->where('status', 'CONFIRMED')
            ->whereDate('created_at', $today)
            ->sum('paid_amount');

        $dailyCollected = $dailyCollectedSales + $dailyPaymentsReceived;

        $totalDebts = (int) Customer::where('merchant_id', $merchant->id)
            ->where('balance', '>', 0)
            ->sum('balance');

        $pendingValidations = Transaction::where('merchant_id', $merchant->id)
            ->where('status', 'PENDING')
            ->count();

        // 2. Bandeau d'action (Alertes)
        $alerts = [];
        if ($pendingValidations > 0) {
            $alerts[] = [
                'type' => 'PENDING_TRANSACTIONS',
                'severity' => 'warning',
                'count' => $pendingValidations,
                'message' => "{$pendingValidations} transaction(s) nécessitent une confirmation humaine.",
                'action_url' => '/transactions?status=PENDING',
            ];
        }

        $lowStockProducts = Product::where('merchant_id', $merchant->id)
            ->where('stock', '<=', 5)
            ->get();

        if ($lowStockProducts->isNotEmpty()) {
            $outOfStockCount = $lowStockProducts->where('stock', 0)->count();
            $lowCount = $lowStockProducts->where('stock', '>', 0)->count();
            $alerts[] = [
                'type' => 'STOCK_ALERT',
                'severity' => $outOfStockCount > 0 ? 'danger' : 'warning',
                'count' => $lowStockProducts->count(),
                'message' => ($outOfStockCount > 0 ? "{$outOfStockCount} produit(s) en rupture de stock. " : "") .
                             ($lowCount > 0 ? "{$lowCount} produit(s) sous le seuil critique." : ""),
                'action_url' => '/products?filter=low_stock',
            ];
        }

        // 3. Flux d'activité récente
        $recentTransactions = Transaction::where('merchant_id', $merchant->id)
            ->with(['customer:id,name', 'items.product:id,name', 'message:id,audio_url,transcript'])
            ->latest('created_at')
            ->limit(10)
            ->get()
            ->map(function ($tx) {
                return [
                    'id' => $tx->id,
                    'created_at' => $tx->created_at->toIso8601String(),
                    'formatted_time' => $tx->created_at->format('H:i'),
                    'type' => $tx->type,
                    'status' => $tx->status,
                    'customer' => $tx->customer ? [
                        'id' => $tx->customer->id,
                        'name' => $tx->customer->name,
                    ] : null,
                    'total_amount' => $tx->total_amount,
                    'paid_amount' => $tx->paid_amount,
                    'due_amount' => max(0, $tx->total_amount - $tx->paid_amount),
                    'items_summary' => $tx->items->map(fn($item) => ($item->product?->name ?? 'Article') . ' (x' . $item->quantity . ')')->join(', '),
                    'message' => $tx->message ? [
                        'id' => $tx->message->id,
                        'transcript' => $tx->message->transcript,
                        'audio_url' => $tx->message->audio_url,
                    ] : null,
                ];
            });

        return response()->json([
            'merchant' => [
                'id' => $merchant->id,
                'name' => $merchant->name,
                'channel_user_id' => $merchant->channel_user_id,
            ],
            'kpis' => [
                'daily_sales' => $dailySales,
                'daily_collected' => $dailyCollected,
                'total_debts' => $totalDebts,
                'pending_validations' => $pendingValidations,
            ],
            'alerts' => $alerts,
            'recent_activity' => $recentTransactions,
        ]);
    }
}
