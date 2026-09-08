<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Merchant;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @tags 5. Messages Vocaux & Traçabilité
 */
class MessageController extends Controller
{
    /**
     * Journal des messages vocaux reçus.
     * 
     * Permet de consulter l'historique brut des messages vocaux reçus de WhatsApp, avec le lien audio d'origine, le texte transcrit par Whisper, l'indice de confiance et le lien vers la transaction extraite.
     * 
     * @param string|null $status Statut de traitement (RECEIVED, TRANSCRIBED, PARSED, FAILED)
     * @param bool|null $failed_only Afficher uniquement les échecs de traitement ou confiances faibles (< 70%)
     */
    public function index(Request $request): JsonResponse
    {
        $merchant = Merchant::first();
        if (!$merchant) {
            return response()->json(['message' => 'Aucun commerce trouvé'], 404);
        }

        $query = Message::where('merchant_id', $merchant->id)
            ->with(['transaction:id,message_id,type,status,total_amount,paid_amount']);

        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }

        if ($request->boolean('failed_only')) {
            $query->where('status', 'FAILED')->orWhere('confidence', '<', 0.70);
        }

        $perPage = $request->query('per_page', 20);
        $messages = $query->latest('received_at')->paginate($perPage);

        return response()->json($messages);
    }

    /**
     * Consulter le détail d'un message vocal et sa transaction associée.
     * 
     * @param string $id Identifiant unique UUID du message
     */
    public function show(string $id): JsonResponse
    {
        $message = Message::with([
            'transaction.customer',
            'transaction.items.product',
        ])->find($id);

        if (!$message) {
            return response()->json(['message' => 'Message introuvable'], 404);
        }

        return response()->json($message);
    }
}
