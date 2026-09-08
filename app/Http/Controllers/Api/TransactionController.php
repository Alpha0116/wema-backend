<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\TransactionProcessor;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * @tags 2. Transactions & Validation
 */
class TransactionController extends Controller
{
    protected TransactionProcessor $processor;

    public function __construct(TransactionProcessor $processor)
    {
        $this->processor = $processor;
    }

    /**
     * Lister les transactions avec filtres.
     * 
     * Permet de filtrer par type (`SALE`, `PAYMENT`, `RESTOCK`, `EXPENSE`), statut (`CONFIRMED`, `PENDING`, `REJECTED`), période (`today`, `7days`, `30days`, ou `from`/`to`), cliente ou produit.
     * 
     * @param string|null $type Type d'opération (SALE, PAYMENT, RESTOCK, EXPENSE)
     * @param string|null $status Statut de l'opération (CONFIRMED, PENDING, REJECTED)
     * @param string|null $period Filtre prédéfini (today, 7days, 30days)
     * @param string|null $from Date de début (YYYY-MM-DD)
     * @param string|null $to Date de fin (YYYY-MM-DD)
     * @param string|null $customer_id Identifiant unique de la cliente
     * @param string|null $product_id Identifiant unique du produit
     * @param string|null $search Terme de recherche libre sur le client ou le produit
     */
    public function index(Request $request): JsonResponse
    {
        $merchant = Merchant::first();
        if (!$merchant) {
            return response()->json(['message' => 'Aucun commerce trouvé'], 404);
        }

        $query = Transaction::where('merchant_id', $merchant->id)
            ->with(['customer:id,name', 'items.product:id,name', 'message:id,audio_url,transcript,confidence']);

        if ($request->filled('type')) {
            $query->where('type', $request->query('type'));
        }

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->filled('customer_id')) {
            $query->where('customer_id', $request->query('customer_id'));
        }

        if ($request->filled('product_id')) {
            $query->whereHas('items', function ($q) use ($request) {
                $q->where('product_id', $request->query('product_id'));
            });
        }

        if ($request->filled('period')) {
            $period = $request->query('period');
            if ($period === 'today') {
                $query->whereDate('created_at', Carbon::today());
            } elseif ($period === '7days') {
                $query->where('created_at', '>=', Carbon::now()->subDays(7));
            } elseif ($period === '30days') {
                $query->where('created_at', '>=', Carbon::now()->subDays(30));
            }
        } elseif ($request->filled('from') && $request->filled('to')) {
            $query->whereBetween('created_at', [
                Carbon::parse($request->query('from'))->startOfDay(),
                Carbon::parse($request->query('to'))->endOfDay(),
            ]);
        }

        if ($request->filled('search')) {
            $search = '%' . $request->query('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->whereHas('customer', function ($cq) use ($search) {
                    $cq->where('name', 'like', $search);
                })->orWhereHas('items.product', function ($pq) use ($search) {
                    $pq->where('name', 'like', $search);
                });
            });
        }

        $perPage = $request->query('per_page', 15);
        $transactions = $query->latest('created_at')->paginate($perPage);

        return response()->json($transactions);
    }

    /**
     * Consulter le détail complet d'une transaction.
     * 
     * Affiche les articles (produit, quantité, prix unitaire), les montants, la cliente et le lien direct vers le message vocal source (audio + transcription + indice de confiance).
     * 
     * @param string $id Identifiant unique UUID de la transaction
     */
    public function show(string $id): JsonResponse
    {
        $transaction = Transaction::with([
            'customer',
            'items.product',
            'message',
        ])->find($id);

        if (!$transaction) {
            return response()->json(['message' => 'Transaction introuvable'], 404);
        }

        return response()->json($transaction);
    }

    /**
     * Valider / Confirmer une transaction en attente.
     * 
     * Passe le statut à `CONFIRMED` et applique automatiquement la décrémentation de stock et l'ajustement du solde débiteur de la cliente.
     * 
     * @param string $id Identifiant unique UUID de la transaction
     */
    public function confirm(string $id): JsonResponse
    {
        $transaction = Transaction::find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaction introuvable'], 404);
        }

        $this->processor->applyTransaction($transaction);

        return response()->json([
            'message' => 'Transaction validée avec succès',
            'transaction' => $transaction->fresh(['customer', 'items.product', 'message']),
        ]);
    }

    /**
     * Rejeter une transaction.
     * 
     * Passe le statut à `REJECTED` sans impacter les stocks ni les créances clientes.
     * 
     * @param string $id Identifiant unique UUID de la transaction
     */
    public function reject(string $id): JsonResponse
    {
        $transaction = Transaction::find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaction introuvable'], 404);
        }

        $this->processor->rejectTransaction($transaction);

        return response()->json([
            'message' => 'Transaction rejetée avec succès',
            'transaction' => $transaction->fresh(),
        ]);
    }

    /**
     * Corriger et modifier une transaction (Correction humaine).
     * 
     * Permet à l'utilisatrice de rectifier les montants, la cliente ou les quantités avant de confirmer.
     * 
     * @param string $id Identifiant unique UUID de la transaction
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $transaction = Transaction::find($id);
        if (!$transaction) {
            return response()->json(['message' => 'Transaction introuvable'], 404);
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|uuid|exists:customers,id',
            'type' => 'nullable|in:SALE,PAYMENT,RESTOCK,EXPENSE',
            'total_amount' => 'nullable|integer|min:0',
            'paid_amount' => 'nullable|integer|min:0',
            'status' => 'nullable|in:PENDING,CONFIRMED,REJECTED',
            'items' => 'nullable|array',
            'items.*.product_id' => 'nullable|uuid|exists:products,id',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.unit_price' => 'required_with:items|integer|min:0',
        ]);

        DB::transaction(function () use ($transaction, $validated) {
            $transaction->fill(array_filter([
                'customer_id' => $validated['customer_id'] ?? $transaction->customer_id,
                'type' => $validated['type'] ?? $transaction->type,
                'total_amount' => $validated['total_amount'] ?? $transaction->total_amount,
                'paid_amount' => $validated['paid_amount'] ?? $transaction->paid_amount,
            ]));
            $transaction->save();

            if (isset($validated['items'])) {
                $transaction->items()->delete();
                foreach ($validated['items'] as $item) {
                    TransactionItem::create([
                        'id' => (string) Str::uuid(),
                        'transaction_id' => $transaction->id,
                        'product_id' => $item['product_id'] ?? null,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                    ]);
                }
            }

            if (($validated['status'] ?? null) === 'CONFIRMED') {
                $this->processor->applyTransaction($transaction);
            }
        });

        return response()->json([
            'message' => 'Transaction mise à jour avec succès',
            'transaction' => $transaction->fresh(['customer', 'items.product', 'message']),
        ]);
    }

    /**
     * Créer manuellement une transaction.
     */
    public function store(Request $request): JsonResponse
    {
        $merchant = Merchant::first();
        if (!$merchant) {
            return response()->json(['message' => 'Aucun commerce trouvé'], 404);
        }

        $validated = $request->validate([
            'customer_id' => 'nullable|uuid|exists:customers,id',
            'type' => 'required|in:SALE,PAYMENT,RESTOCK,EXPENSE',
            'total_amount' => 'required|integer|min:0',
            'paid_amount' => 'required|integer|min:0',
            'items' => 'nullable|array',
            'items.*.product_id' => 'nullable|uuid|exists:products,id',
            'items.*.quantity' => 'required_with:items|integer|min:1',
            'items.*.unit_price' => 'required_with:items|integer|min:0',
        ]);

        $transaction = DB::transaction(function () use ($merchant, $validated) {
            $tx = Transaction::create([
                'id' => (string) Str::uuid(),
                'merchant_id' => $merchant->id,
                'customer_id' => $validated['customer_id'] ?? null,
                'type' => $validated['type'],
                'total_amount' => $validated['total_amount'],
                'paid_amount' => $validated['paid_amount'],
                'status' => 'CONFIRMED',
            ]);

            if (!empty($validated['items'])) {
                foreach ($validated['items'] as $item) {
                    TransactionItem::create([
                        'id' => (string) Str::uuid(),
                        'transaction_id' => $tx->id,
                        'product_id' => $item['product_id'] ?? null,
                        'quantity' => $item['quantity'],
                        'unit_price' => $item['unit_price'],
                    ]);
                }
            }

            $this->processor->applyTransaction($tx);
            return $tx;
        });

        return response()->json([
            'message' => 'Transaction créée avec succès',
            'transaction' => $transaction->fresh(['customer', 'items.product']),
        ], 201);
    }
}
