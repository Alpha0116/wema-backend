<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\TransactionItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

/**
 * @tags 4. Produits & Stocks
 */
class ProductController extends Controller
{
    /**
     * Lister le catalogue des produits et le suivi du stock.
     * 
     * Retourne la liste des produits avec quantités disponibles, prix unitaires, et indicateurs visuels d'alerte de stock bas ou de rupture.
     * 
     * @param string|null $search Recherche par nom ou variante orale du produit
     * @param string|null $filter Filtre spécifique (`low_stock` pour stock bas, `out_of_stock` pour rupture)
     * @param int|null $low_stock_threshold Seuil d'alerte de stock bas (défaut: 5)
     */
    public function index(Request $request): JsonResponse
    {
        $merchant = Merchant::first();
        if (!$merchant) {
            return response()->json(['message' => 'Aucun commerce trouvé'], 404);
        }

        $lowStockThreshold = (int) $request->query('low_stock_threshold', 5);

        $query = Product::where('merchant_id', $merchant->id);

        if ($request->filled('search')) {
            $search = '%' . $request->query('search') . '%';
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', $search)
                  ->orWhere('aliases', 'like', $search);
            });
        }

        if ($request->query('filter') === 'low_stock') {
            $query->where('stock', '<=', $lowStockThreshold);
        } elseif ($request->query('filter') === 'out_of_stock') {
            $query->where('stock', 0);
        }

        $products = $query->orderBy('stock', 'asc')->get()->map(function ($product) use ($lowStockThreshold) {
            return [
                'id' => $product->id,
                'name' => $product->name,
                'aliases' => $product->aliases ?? [],
                'unit_price' => $product->unit_price,
                'stock' => $product->stock,
                'is_low_stock' => $product->stock > 0 && $product->stock <= $lowStockThreshold,
                'is_out_of_stock' => $product->stock === 0,
                'created_at' => $product->created_at,
            ];
        });

        $totalProducts = Product::where('merchant_id', $merchant->id)->count();
        $outOfStockCount = Product::where('merchant_id', $merchant->id)->where('stock', 0)->count();
        $lowStockCount = Product::where('merchant_id', $merchant->id)->where('stock', '>', 0)->where('stock', '<=', $lowStockThreshold)->count();

        return response()->json([
            'summary' => [
                'total_products' => $totalProducts,
                'low_stock_count' => $lowStockCount,
                'out_of_stock_count' => $outOfStockCount,
                'low_stock_threshold' => $lowStockThreshold,
            ],
            'data' => $products,
        ]);
    }

    /**
     * Obtenir la fiche produit et son historique de mouvements de stock.
     * 
     * Reconstitue les sorties (ventes) et entrées (réapprovisionnements) avec dates et quantités.
     * 
     * @param string $id Identifiant unique UUID du produit
     */
    public function show(string $id): JsonResponse
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['message' => 'Produit introuvable'], 404);
        }

        $movements = TransactionItem::where('product_id', $product->id)
            ->with(['transaction.customer'])
            ->whereHas('transaction', fn($q) => $q->where('status', 'CONFIRMED'))
            ->get()
            ->map(function ($item) {
                $tx = $item->transaction;
                return [
                    'id' => $item->id,
                    'date' => $tx->created_at->toIso8601String(),
                    'type' => $tx->type,
                    'direction' => $tx->type === 'RESTOCK' ? 'IN' : 'OUT',
                    'quantity' => $item->quantity,
                    'unit_price' => $item->unit_price,
                    'total_price' => $item->quantity * $item->unit_price,
                    'customer' => $tx->customer?->name,
                    'transaction_id' => $tx->id,
                ];
            })
            ->sortByDesc('date')
            ->values();

        $totalSold = $movements->where('direction', 'OUT')->sum('quantity');
        $totalRestocked = $movements->where('direction', 'IN')->sum('quantity');

        return response()->json([
            'product' => $product,
            'summary' => [
                'current_stock' => $product->stock,
                'unit_price' => $product->unit_price,
                'total_sold_quantity' => $totalSold,
                'total_restocked_quantity' => $totalRestocked,
            ],
            'movements' => $movements,
        ]);
    }

    /**
     * Ajouter un produit au catalogue.
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
            'unit_price' => 'required|integer|min:0',
            'stock' => 'nullable|integer|min:0',
        ]);

        $product = Product::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'name' => $validated['name'],
            'aliases' => $validated['aliases'] ?? [],
            'unit_price' => $validated['unit_price'],
            'stock' => $validated['stock'] ?? 0,
        ]);

        return response()->json([
            'message' => 'Produit ajouté avec succès',
            'product' => $product,
        ], 201);
    }

    /**
     * Mettre à jour un produit / son stock.
     * 
     * @param string $id Identifiant unique UUID du produit
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['message' => 'Produit introuvable'], 404);
        }

        $validated = $request->validate([
            'name' => 'sometimes|required|string|max:255',
            'aliases' => 'nullable|array',
            'aliases.*' => 'string|max:255',
            'unit_price' => 'sometimes|required|integer|min:0',
            'stock' => 'sometimes|required|integer|min:0',
        ]);

        $product->update($validated);

        return response()->json([
            'message' => 'Produit mis à jour avec succès',
            'product' => $product,
        ]);
    }

    /**
     * Supprimer un produit.
     * 
     * @param string $id Identifiant unique UUID du produit
     */
    public function destroy(string $id): JsonResponse
    {
        $product = Product::find($id);
        if (!$product) {
            return response()->json(['message' => 'Produit introuvable'], 404);
        }

        $product->delete();

        return response()->json(['message' => 'Produit supprimé avec succès']);
    }
}
