<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Message;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use Carbon\Carbon;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class WemaDemoSeeder extends Seeder
{
    public function run(): void
    {
        // 1. Create Default Merchant (Maman Viviane - Dantokpa)
        $merchant = Merchant::create([
            'id' => (string) Str::uuid(),
            'channel_user_id' => '22997001122',
            'name' => 'Maman Viviane (Boutique Marché Dantokpa)',
            'language' => 'fr',
        ]);

        // 2. Products
        $pOil = Product::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'name' => "Bidon d'huile 5L",
            'aliases' => ['huile', "bidon d'huile", 'huile 5 litres', 'huile végétale'],
            'unit_price' => 6000,
            'stock' => 17,
        ]);

        $pRice = Product::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'name' => 'Sac de riz 25kg',
            'aliases' => ['riz', 'sac de riz', 'riz parfumé', 'gros sac de riz'],
            'unit_price' => 18500,
            'stock' => 3, // Low stock
        ]);

        $pTomato = Product::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'name' => 'Carton de tomates',
            'aliases' => ['tomate', 'carton de tomate', 'tomates fraîches'],
            'unit_price' => 12000,
            'stock' => 0, // Rupture
        ]);

        $pSugar = Product::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'name' => 'Sac de sucre 50kg',
            'aliases' => ['sucre', 'sac de sucre', 'sucre blanc'],
            'unit_price' => 25000,
            'stock' => 8,
        ]);

        $pSoap = Product::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'name' => 'Carton de savon de ménage',
            'aliases' => ['savon', 'carton savon', 'savon bleu'],
            'unit_price' => 4500,
            'stock' => 20,
        ]);

        // 3. Customers
        $cChantal = Customer::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'name' => 'Maman Chantal',
            'aliases' => ['Chantal', 'Tantine Chantal', 'Maman Chanta'],
            'balance' => 4000, // owes 4000 FCFA
        ]);

        $cKoffi = Customer::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'name' => 'Koffi le boutiquier',
            'aliases' => ['Koffi', 'Papa Koffi', 'Boutique Koffi'],
            'balance' => 18500, // owes 18500 FCFA
        ]);

        $cPascaline = Customer::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'name' => 'Maman Pascaline',
            'aliases' => ['Pascaline', 'Tante Pascaline'],
            'balance' => 7000, // owes 7000 FCFA
        ]);

        $cJeanLuc = Customer::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'name' => 'Jean-Luc',
            'aliases' => ['Jean', 'Luc', 'Monsieur Jean-Luc'],
            'balance' => 0,
        ]);

        // 4. Messages & Transactions
        // Message 1: Vente à crédit partiel (Maman Chantal)
        $msg1 = Message::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'external_id' => 'wamid.HBgLMjI5OTcwMDExMjIVAgASGBQzQTFDOTk4OTI1OUIyNTJBRDU1QwA=',
            'audio_url' => 'https://wema-assets.local/audio/demo-chantal.mp3',
            'transcript' => "J'ai vendu trois bidons d'huile à Maman Chantal, elle a payé deux mille, elle doit quatre mille.",
            'confidence' => 0.96,
            'status' => 'PARSED',
            'received_at' => Carbon::now()->subMinutes(35),
        ]);

        $tx1 = Transaction::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'customer_id' => $cChantal->id,
            'message_id' => $msg1->id,
            'type' => 'SALE',
            'total_amount' => 6000,
            'paid_amount' => 2000,
            'status' => 'CONFIRMED',
            'created_at' => Carbon::now()->subMinutes(35),
        ]);

        TransactionItem::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx1->id,
            'product_id' => $pOil->id,
            'quantity' => 3,
            'unit_price' => 2000,
        ]);

        // Message 2: Vente à crédit total (Koffi)
        $msg2 = Message::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'external_id' => 'wamid.HBgLMjI5OTcwMDExMjIVAgASGBQzQTFDOTk4OTI1OUIyNTJBRDU1RAA=',
            'audio_url' => 'https://wema-assets.local/audio/demo-koffi.mp3',
            'transcript' => "Koffi a pris un sac de riz 25 kilos, il n'a rien payé aujourd'hui, il va solder vendredi.",
            'confidence' => 0.94,
            'status' => 'PARSED',
            'received_at' => Carbon::now()->subHours(2),
        ]);

        $tx2 = Transaction::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'customer_id' => $cKoffi->id,
            'message_id' => $msg2->id,
            'type' => 'SALE',
            'total_amount' => 18500,
            'paid_amount' => 0,
            'status' => 'CONFIRMED',
            'created_at' => Carbon::now()->subHours(2),
        ]);

        TransactionItem::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx2->id,
            'product_id' => $pRice->id,
            'quantity' => 1,
            'unit_price' => 18500,
        ]);

        // Message 3: Vente au comptant (Jean-Luc)
        $msg3 = Message::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'external_id' => 'wamid.HBgLMjI5OTcwMDExMjIVAgASGBQzQTFDOTk4OTI1OUIyNTJBRDU1RQA=',
            'audio_url' => 'https://wema-assets.local/audio/demo-jeanluc.mp3',
            'transcript' => "Jean-Luc a payé cash un sac de sucre de 50 kilos à vingt-cinq mille francs.",
            'confidence' => 0.98,
            'status' => 'PARSED',
            'received_at' => Carbon::now()->subHours(4),
        ]);

        $tx3 = Transaction::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'customer_id' => $cJeanLuc->id,
            'message_id' => $msg3->id,
            'type' => 'SALE',
            'total_amount' => 25000,
            'paid_amount' => 25000,
            'status' => 'CONFIRMED',
            'created_at' => Carbon::now()->subHours(4),
        ]);

        TransactionItem::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx3->id,
            'product_id' => $pSugar->id,
            'quantity' => 1,
            'unit_price' => 25000,
        ]);

        // Message 4: Transaction EN ATTENTE DE VALIDATION (Low confidence / Ambiguë pour démo)
        $msg4 = Message::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'external_id' => 'wamid.HBgLMjI5OTcwMDExMjIVAgASGBQzQTFDOTk4OTI1OUIyNTJBRDU1RkA=',
            'audio_url' => 'https://wema-assets.local/audio/demo-ambigu.mp3',
            'transcript' => "J'ai donné deux cartons de savon et un bidon à la dame du fond, elle a versé cinq mille...",
            'confidence' => 0.62,
            'status' => 'PARSED',
            'received_at' => Carbon::now()->subMinutes(12),
        ]);

        $tx4 = Transaction::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'customer_id' => null, // Non identifié automatiquement -> nécessite validation humaine
            'message_id' => $msg4->id,
            'type' => 'SALE',
            'total_amount' => 15000,
            'paid_amount' => 5000,
            'status' => 'PENDING',
            'created_at' => Carbon::now()->subMinutes(12),
        ]);

        TransactionItem::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx4->id,
            'product_id' => $pSoap->id,
            'quantity' => 2,
            'unit_price' => 4500,
        ]);

        TransactionItem::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx4->id,
            'product_id' => $pOil->id,
            'quantity' => 1,
            'unit_price' => 6000,
        ]);

        // Message 5: Réapprovisionnement (RESTOCK)
        $msg5 = Message::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'external_id' => 'wamid.HBgLMjI5OTcwMDExMjIVAgASGBQzQTFDOTk4OTI1OUIyNTJBRDU1R0E=',
            'audio_url' => 'https://wema-assets.local/audio/demo-restock.mp3',
            'transcript' => "Arrivage de dix bidons d'huile pour le stock ce matin.",
            'confidence' => 0.95,
            'status' => 'PARSED',
            'received_at' => Carbon::now()->subHours(6),
        ]);

        $tx5 = Transaction::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'customer_id' => null,
            'message_id' => $msg5->id,
            'type' => 'RESTOCK',
            'total_amount' => 50000,
            'paid_amount' => 50000,
            'status' => 'CONFIRMED',
            'created_at' => Carbon::now()->subHours(6),
        ]);

        TransactionItem::create([
            'id' => (string) Str::uuid(),
            'transaction_id' => $tx5->id,
            'product_id' => $pOil->id,
            'quantity' => 10,
            'unit_price' => 5000,
        ]);
    }
}
