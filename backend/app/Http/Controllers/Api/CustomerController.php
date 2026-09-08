<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Merchant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @tags 3. Clients & Créances
 */
class CustomerController extends Controller
{
    /**
     * Lister les clientes et le suivi des créances.
     * 
     * Retourne la liste des clientes triée par défaut par solde décroissant (les dettes les plus importantes en premier), avec recherche possible sur le nom ou les variantes/surnoms oraux.
     * 
     * @param string|null $search Terme de recherche par nom ou alias
     * @param bool|null $only_debtors Filtrer uniquement les clientes ayant une créance active
     * @param string|null $sort_by Champ de tri (défaut: balance)
     * @param string|null $sort_order Ordre de tri asc/desc (défaut: desc)
     */
    public function index(Request $request): JsonResponse
    {
        $merchant = Merchant::first();
        if (!$merchant) {
            return response()->json(['message' => 'Aucun commerce trouvé'], 404);
        }

        $query = Customer::where('merchant_id', $merchant->id);

        if ($request->filled('search')) {
            $search = '%' . $request->query('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('aliases', 'like', $search);
            });
        }

        if ($request->boolean('only_debtors')) {
            $query->where('balance', '>', 0);
        }

        $sortBy = $request->query('sort_by', 'balance');
        $sortOrder = $request->query('sort_order', 'desc');
        $customers = $query->orderBy($sortBy, $sortOrder)->get();

        $totalDebts = $customers->sum('balance');
        $debtorCount = $customers->where('balance', '>', 0)->count();

        return response()->json([
            'summary' => [
                'total_customers' => $customers->count(),
                'debtors_count' => $debtorCount,
                'total_outstanding_debt' => $totalDebts,
            ],
            'data' => $customers,
        ]);
    }

    /**
     * Obtenir la fiche cliente et son historique de dettes/transactions.
     * 
     * Affiche le solde actuel de la cliente, les opérations impayées ou partiellement payées, et l'historique complet.
     * 
     * @param string $id Identifiant unique UUID de la cliente
     */
    public function show(string $id): JsonResponse
    {
        $customer = Customer::with([
            'transactions' => function ($q) {
                $q->with(['items.product', 'message'])
                  ->latest('created_at');
            }
        ])->find($id);

        if (!$customer) {
            return response()->json(['message' => 'Cliente introuvable'], 404);
        }

        $unpaidTransactions = $customer->transactions->filter(function ($tx) {
            return $tx->type === 'SALE' && ($tx->total_amount - $tx->paid_amount) > 0;
        })->values();

        return response()->json([
            'customer' => $customer,
            'summary' => [
                'current_balance' => $customer->balance,
                'total_transactions_count' => $customer->transactions->count(),
                'unpaid_transactions_count' => $unpaidTransactions->count(),
            ],
            'unpaid_transactions' => $unpaidTransactions,
            'history' => $customer->transactions,
        ]);
    }

    /**
     * Enregistrer une nouvelle cliente.
     */
    public function store(Request $request): JsonResponse
    {
        $merchant = Merchant::first();
        if (!$merchant) {
            return response()->json(['message' => 'Aucun commerce trouvé'], 404);
        }

        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'aliases' => 'nullable|array',
            'aliases.*' => 'string|max:255',
            'balance' => 'nullable|integer|min:0',
        ]);

        $customer = Customer::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'name' => $validated['name'],
            'aliases' => $validated['aliases'] ?? [],
            'balance' => $validated['balance'] ?? 0,
        ]);

        return response()->json([
            'message' => 'Cliente enregistrée avec succès',
            'customer' => $customer,
        ], 201);
    }

    /**
     * Mettre à jour une cliente (nom, variantes/alias, solde).
     * 
     * @param string $id Identifiant unique UUID de la cliente
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['message' => 'Cliente introuvable'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'aliases' => 'nullable|array',
            'aliases.*' => 'string|max:255',
            'balance' => 'nullable|integer',
        ]);

        $customer->update($validated);

        return response()->json([
            'message' => 'Cliente mise à jour avec succès',
            'customer' => $customer,
        ]);
    }

    /**
     * Supprimer une cliente.
     * 
     * @param string $id Identifiant unique UUID de la cliente
     */
    public function destroy(string $id): JsonResponse
    {
        $customer = Customer::find($id);
        if (!$customer) {
            return response()->json(['message' => 'Cliente introuvable'], 404);
        }

        $customer->delete();

        return response()->json(['message' => 'Cliente supprimée avec succès']);
    }
}
