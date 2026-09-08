<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Message;
use App\Models\Product;
use App\Models\Transaction;
use App\Models\TransactionItem;
use App\Services\ExtractionService;
use App\Services\TransactionProcessor;
use App\Services\TranscriptionService;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * @tags 6. WhatsApp Webhooks & Simulation Vocale
 */
class WhatsAppWebhookController extends Controller
{
    protected WhatsAppService $whatsapp;
    protected TranscriptionService $transcription;
    protected ExtractionService $extraction;
    protected TransactionProcessor $processor;

    public function __construct(
        WhatsAppService $whatsapp,
        TranscriptionService $transcription,
        ExtractionService $extraction,
        TransactionProcessor $processor
    ) {
        $this->whatsapp = $whatsapp;
        $this->transcription = $transcription;
        $this->extraction = $extraction;
        $this->processor = $processor;
    }

    /**
     * Handshake de validation du Webhook Meta WhatsApp (GET).
     * 
     * Utilisé par Meta for Developers pour vérifier l'authenticité de l'URL de rappel webhook.
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode', '');
        $token = $request->query('hub_verify_token', '');
        $challenge = $request->query('hub_challenge', '');

        $verified = $this->whatsapp->verifyWebhook($mode, $token, $challenge);
        if ($verified !== null) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Réception et traitement des messages WhatsApp (POST).
     * 
     * Reçoit les payloads de Meta (notes vocales .ogg ou textes), télécharge l'audio, lance Whisper pour la transcription, extrait la transaction par LLM, met à jour la base de données et renvoie la confirmation WhatsApp à la commerçante.
     */
    public function handle(Request $request): JsonResponse
    {
        $payload = $request->all();
        Log::info('WhatsApp Webhook Payload Received', ['payload' => $payload]);

        $entry = $payload['entry'][0]['changes'][0]['value'] ?? null;
        if (!$entry || empty($entry['messages'])) {
            return response()->json(['status' => 'ignored']);
        }

        $incoming = $entry['messages'][0];
        $fromNumber = $incoming['from'] ?? null;
        $msgId = $incoming['id'] ?? null;
        $msgType = $incoming['type'] ?? 'text';

        $merchant = Merchant::firstOrCreate(
            ['channel_user_id' => $fromNumber],
            ['name' => "Commerçante ({$fromNumber})", 'language' => 'fr']
        );

        $transcript = '';
        $audioUrl = null;
        $confidence = 0.90;

        if ($msgType === 'audio' || $msgType === 'voice') {
            $mediaId = $incoming['audio']['id'] ?? $incoming['voice']['id'] ?? null;
            if ($mediaId) {
                $audioUrl = $this->whatsapp->downloadMedia($mediaId);
                if ($audioUrl) {
                    $storagePath = storage_path('app/public/' . str_replace('/storage/', '', parse_url($audioUrl, PHP_URL_PATH)));
                    if (file_exists($storagePath)) {
                        $transResult = $this->transcription->transcribe($storagePath);
                        $transcript = $transResult['transcript'] ?? '';
                        $confidence = $transResult['confidence'] ?? 0.85;
                    }
                }
            }
        } elseif ($msgType === 'text') {
            $transcript = $incoming['text']['body'] ?? '';
        }

        if (empty($transcript)) {
            Message::create([
                'id' => (string) Str::uuid(),
                'merchant_id' => $merchant->id,
                'external_id' => $msgId,
                'audio_url' => $audioUrl,
                'transcript' => null,
                'confidence' => 0.0,
                'status' => 'FAILED',
                'received_at' => Carbon::now(),
            ]);

            $this->whatsapp->sendTextMessage($fromNumber, "Désolé, je n'ai pas pu comprendre votre message audio. Veuillez réessayer.");
            return response()->json(['status' => 'unintelligible']);
        }

        $messageRecord = Message::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'external_id' => $msgId,
            'audio_url' => $audioUrl,
            'transcript' => $transcript,
            'confidence' => $confidence,
            'status' => 'TRANSCRIBED',
            'received_at' => Carbon::now(),
        ]);

        $extracted = $this->extraction->extractTransaction($transcript, $merchant);
        $finalConfidence = min($confidence, $extracted['confidence'] ?? 0.90);
        $status = $finalConfidence >= 0.75 ? 'CONFIRMED' : 'PENDING';

        $tx = Transaction::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'customer_id' => $extracted['matched_customer_id'] ?? null,
            'message_id' => $messageRecord->id,
            'type' => $extracted['type'] ?? 'SALE',
            'total_amount' => $extracted['total_amount'] ?? 0,
            'paid_amount' => $extracted['paid_amount'] ?? 0,
            'status' => $status,
        ]);

        if (!empty($extracted['items'])) {
            foreach ($extracted['items'] as $item) {
                TransactionItem::create([
                    'id' => (string) Str::uuid(),
                    'transaction_id' => $tx->id,
                    'product_id' => $item['matched_product_id'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'] ?? 0,
                ]);
            }
        }

        $messageRecord->update([
            'confidence' => $finalConfidence,
            'status' => 'PARSED',
        ]);

        if ($status === 'CONFIRMED') {
            $this->processor->applyTransaction($tx);
        }

        $replyText = $extracted['confirmation_message'] ?? "Opération enregistrée avec succès.";
        if ($status === 'PENDING') {
            $replyText .= "\n⚠️ Une validation manuelle est en attente sur votre tableau de bord.";
        }
        $this->whatsapp->sendTextMessage($fromNumber, $replyText);

        return response()->json(['status' => 'processed', 'transaction_id' => $tx->id]);
    }

    /**
     * Simulateur vocal & textuel direct (Pour Frontend & Démo).
     * 
     * Permet au Frontend de tester la dictée vocale ou textuelle (ex: "J'ai vendu 3 bidons d'huile à Maman Chantal...") et de recevoir directement le résultat structuré en temps réel sans passer par un téléphone physique.
     * 
     * @param string|null $text Texte dicté en français parlé de marché
     * @param float|null $confidence Score de confiance simulé (0.0 à 1.0)
     */
    public function simulate(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'text' => 'nullable|string',
            'audio' => 'nullable|file|mimes:mp3,wav,ogg,m4a,mp4',
            'confidence' => 'nullable|numeric|between:0,1',
        ]);

        $merchant = Merchant::first();
        if (!$merchant) {
            return response()->json(['message' => 'Aucun commerce trouvé'], 404);
        }

        $transcript = $validated['text'] ?? '';
        $audioUrl = null;
        $confidence = $validated['confidence'] ?? 0.95;

        if ($request->hasFile('audio')) {
            $file = $request->file('audio');
            $path = $file->store('audio', 'public');
            $audioUrl = '/storage/' . $path;

            $transResult = $this->transcription->transcribe(storage_path('app/public/' . $path));
            if (!empty($transResult['transcript'])) {
                $transcript = $transResult['transcript'];
                $confidence = $transResult['confidence'];
            }
        }

        if (empty($transcript)) {
            return response()->json(['message' => 'Veuillez fournir du texte ou un fichier audio valide.'], 422);
        }

        $messageRecord = Message::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'external_id' => 'sim_' . Str::random(16),
            'audio_url' => $audioUrl ?? 'https://wema-assets.local/audio/sample.mp3',
            'transcript' => $transcript,
            'confidence' => $confidence,
            'status' => 'TRANSCRIBED',
            'received_at' => Carbon::now(),
        ]);

        $extracted = $this->extraction->extractTransaction($transcript, $merchant);
        $finalConfidence = min($confidence, $extracted['confidence'] ?? 0.90);
        $status = $finalConfidence >= 0.75 ? 'CONFIRMED' : 'PENDING';

        $tx = Transaction::create([
            'id' => (string) Str::uuid(),
            'merchant_id' => $merchant->id,
            'customer_id' => $extracted['matched_customer_id'] ?? null,
            'message_id' => $messageRecord->id,
            'type' => $extracted['type'] ?? 'SALE',
            'total_amount' => $extracted['total_amount'] ?? 0,
            'paid_amount' => $extracted['paid_amount'] ?? 0,
            'status' => $status,
        ]);

        if (!empty($extracted['items'])) {
            foreach ($extracted['items'] as $item) {
                TransactionItem::create([
                    'id' => (string) Str::uuid(),
                    'transaction_id' => $tx->id,
                    'product_id' => $item['matched_product_id'] ?? null,
                    'quantity' => $item['quantity'] ?? 1,
                    'unit_price' => $item['unit_price'] ?? 0,
                ]);
            }
        }

        $messageRecord->update([
            'confidence' => $finalConfidence,
            'status' => 'PARSED',
        ]);

        if ($status === 'CONFIRMED') {
            $this->processor->applyTransaction($tx);
        }

        return response()->json([
            'simulation_success' => true,
            'transcript' => $transcript,
            'status' => $status,
            'confidence' => $finalConfidence,
            'extracted' => $extracted,
            'confirmation_message' => $extracted['confirmation_message'] ?? '',
            'transaction' => $tx->fresh(['customer', 'items.product', 'message']),
        ]);
    }
}
