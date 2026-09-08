<?php

namespace App\Services;

use App\Models\Merchant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExtractionService
{
    protected ?string $openaiApiKey;
    protected ?string $groqApiKey;

    public function __construct()
    {
        $this->openaiApiKey = config('services.openai.api_key', env('OPENAI_API_KEY'));
        $this->groqApiKey = config('services.groq.api_key', env('GROQ_API_KEY'));
    }

    /**
     * Extract structured transaction details from transcribed speech.
     */
    public function extractTransaction(string $text, Merchant $merchant): array
    {
        // Load existing products and customers for entity resolution
        $merchant->load(['products', 'customers']);
        $productsList = $merchant->products->map(fn($p) => [
            'id' => $p->id,
            'name' => $p->name,
            'aliases' => $p->aliases ?? [],
            'unit_price' => $p->unit_price,
            'stock' => $p->stock,
        ])->toArray();

        $customersList = $merchant->customers->map(fn($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'aliases' => $c->aliases ?? [],
            'balance' => $c->balance,
        ])->toArray();

        $systemPrompt = <<<PROMPT
Tu es l'assistant d'extraction intelligente de l'application Wemá (« Le cahier qui écoute ») pour les commerçantes des marchés au Bénin (Cotonou, Dantokpa, etc.).
Ton rôle est d'analyser le texte retranscrit d'un message vocal dicté par une commerçante et de le transformer en données JSON structurées de transaction.

Comprends le français parlé de marché avec des expressions béninoises (ex: "elle a versé 2000", "elle doit 4000", "au comptant", "à crédit", "restock", "arrivage").

Catalogue de produits connus :
{$this->jsonEncodePretty($productsList)}

Liste des clients connus :
{$this->jsonEncodePretty($customersList)}

RÈGLES D'EXTRACTION :
1. Type d'opération :
   - 'SALE' : Vente d'un ou plusieurs produits (au comptant ou à crédit).
   - 'PAYMENT' : Remboursement ou versement d'une dette client.
   - 'RESTOCK' : Réapprovisionnement / Arrivage de marchandises.
   - 'EXPENSE' : Dépense de la boutique (transport, loyer, sac plastique, etc.).
2. Fais correspondre ('match') au maximum le client et les produits avec la liste connue ci-dessus. S'il s'agit d'un nouveau client ou produit, indique son nom textuel sans ID.
3. Calcule rigoureusement total_amount, paid_amount et le solde restant.
4. Génère un 'confirmation_message' court et clair en français adapté WhatsApp (ex: "Noté : 3 bidons d'huile, 6 000 F. Payé 2 000 F. Maman Chantal doit maintenant 4 000 F. Stock huile restant : 14.").
5. Évalue ta 'confidence' (flottant entre 0.0 et 1.0). Si des informations sont manquantes ou floues, donne une confidence < 0.75.

Tu DOIS retourner UNIQUEMENT un objet JSON valide suivant ce schéma exact :
{
  "type": "SALE" | "PAYMENT" | "RESTOCK" | "EXPENSE",
  "matched_customer_id": string | null,
  "customer_name": string | null,
  "items": [
    {
      "matched_product_id": string | null,
      "product_name": string,
      "quantity": number,
      "unit_price": number,
      "total_price": number
    }
  ],
  "total_amount": number,
  "paid_amount": number,
  "confidence": number,
  "confirmation_message": string
}
PROMPT;

        // 1. Call OpenAI (GPT-4o-mini with JSON response)
        if (!empty($this->openaiApiKey)) {
            try {
                $response = Http::withToken($this->openaiApiKey)
                    ->post('https://api.openai.com/v1/chat/completions', [
                        'model' => 'gpt-4o-mini',
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => "Message de la commerçante : \"{$text}\""],
                        ],
                        'response_format' => ['type' => 'json_object'],
                        'temperature' => 0.1,
                    ]);

                if ($response->successful()) {
                    $jsonContent = $response->json('choices.0.message.content');
                    $parsed = json_decode($jsonContent, true);
                    if ($parsed) {
                        return $parsed;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('OpenAI Extraction error: ' . $e->getMessage());
            }
        }

        // 2. Call Groq LLaMA
        if (!empty($this->groqApiKey)) {
            try {
                $response = Http::withToken($this->groqApiKey)
                    ->post('https://api.groq.com/openai/v1/chat/completions', [
                        'model' => 'llama-3.3-70b-versatile',
                        'messages' => [
                            ['role' => 'system', 'content' => $systemPrompt],
                            ['role' => 'user', 'content' => "Message de la commerçante : \"{$text}\""],
                        ],
                        'response_format' => ['type' => 'json_object'],
                        'temperature' => 0.1,
                    ]);

                if ($response->successful()) {
                    $jsonContent = $response->json('choices.0.message.content');
                    $parsed = json_decode($jsonContent, true);
                    if ($parsed) {
                        return $parsed;
                    }
                }
            } catch (\Throwable $e) {
                Log::error('Groq Extraction error: ' . $e->getMessage());
            }
        }

        // 3. Smart Rule-based / Local Fallback Parser for demo when keys aren't provided yet
        return $this->fallbackParser($text, $merchant);
    }

    protected function fallbackParser(string $text, Merchant $merchant): array
    {
        $lower = mb_strtolower($text);
        
        // Find customer
        $matchedCustomer = null;
        foreach ($merchant->customers as $customer) {
            if (str_contains($lower, mb_strtolower($customer->name))) {
                $matchedCustomer = $customer;
                break;
            }
            if (!empty($customer->aliases)) {
                foreach ($customer->aliases as $alias) {
                    if (str_contains($lower, mb_strtolower($alias))) {
                        $matchedCustomer = $customer;
                        break 2;
                    }
                }
            }
        }

        // Find products
        $items = [];
        $total = 0;
        foreach ($merchant->products as $product) {
            $namesToCheck = array_merge([$product->name], $product->aliases ?? []);
            foreach ($namesToCheck as $alias) {
                if (str_contains($lower, mb_strtolower($alias))) {
                    // Try to find quantity (e.g. "3 bidons", "deux sacs", etc.)
                    preg_match('/(\d+)\s+' . preg_quote(mb_strtolower($alias), '/') . '/u', $lower, $qtyMatches);
                    $quantity = isset($qtyMatches[1]) ? (int) $qtyMatches[1] : 1;
                    $itemTotal = $quantity * $product->unit_price;
                    $total += $itemTotal;

                    $items[] = [
                        'matched_product_id' => $product->id,
                        'product_name' => $product->name,
                        'quantity' => $quantity,
                        'unit_price' => $product->unit_price,
                        'total_price' => $itemTotal,
                    ];
                    break;
                }
            }
        }

        // Detect type
        $type = 'SALE';
        if (str_contains($lower, 'arrivage') || str_contains($lower, 'restock') || str_contains($lower, 'reçu')) {
            $type = 'RESTOCK';
        } elseif (str_contains($lower, 'rembourse') || str_contains($lower, 'solder') || str_contains($lower, 'versé sa dette')) {
            $type = 'PAYMENT';
        } elseif (str_contains($lower, 'dépense') || str_contains($lower, 'transport')) {
            $type = 'EXPENSE';
        }

        $paid = $total;
        if (preg_match('/pay[ée]\s*(\d+)/u', $lower, $m)) {
            $paid = (int) $m[1];
        } elseif (preg_match('/vers[ée]\s*(\d+)/u', $lower, $m)) {
            $paid = (int) $m[1];
        } elseif (str_contains($lower, 'rien payé') || str_contains($lower, 'à crédit')) {
            $paid = 0;
        }

        $unpaid = max(0, $total - $paid);
        $custName = $matchedCustomer ? $matchedCustomer->name : 'Client';

        return [
            'type' => $type,
            'matched_customer_id' => $matchedCustomer?->id,
            'customer_name' => $matchedCustomer?->name,
            'items' => $items,
            'total_amount' => $total,
            'paid_amount' => $paid,
            'confidence' => count($items) > 0 ? 0.92 : 0.65,
            'confirmation_message' => "Noté : Opération enregistrée pour {$custName}. Total: {$total} F, Payé: {$paid} F" . ($unpaid > 0 ? ", Reste dû: {$unpaid} F" : ""),
        ];
    }

    private function jsonEncodePretty(mixed $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    }
}
