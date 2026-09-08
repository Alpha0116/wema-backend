<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class WhatsAppService
{
    protected ?string $token;
    protected ?string $phoneNumberId;
    protected ?string $verifyToken;

    public function __construct()
    {
        $this->token = config('services.whatsapp.token', env('WHATSAPP_TOKEN'));
        $this->phoneNumberId = config('services.whatsapp.phone_number_id', env('WHATSAPP_PHONE_NUMBER_ID'));
        $this->verifyToken = config('services.whatsapp.verify_token', env('WHATSAPP_VERIFY_TOKEN', 'wema_secret_verify_token_2026'));
    }

    /**
     * Validate incoming Meta Webhook Challenge.
     */
    public function verifyWebhook(string $mode, string $token, string $challenge): ?string
    {
        if ($mode === 'subscribe' && $token === $this->verifyToken) {
            return $challenge;
        }
        return null;
    }

    /**
     * Download media from Meta Graph API.
     */
    public function downloadMedia(string $mediaId): ?string
    {
        if (empty($this->token)) {
            Log::warning('WHATSAPP_TOKEN missing, cannot download media ID ' . $mediaId);
            return null;
        }

        try {
            // Step 1: Get media URL
            $urlResponse = Http::withToken($this->token)
                ->get("https://graph.facebook.com/v20.0/{$mediaId}");

            if (!$urlResponse->successful()) {
                Log::error('Failed to get WhatsApp media URL: ' . $urlResponse->body());
                return null;
            }

            $mediaUrl = $urlResponse->json('url');
            $mimeType = $urlResponse->json('mime_type');
            $ext = str_contains($mimeType, 'ogg') || str_contains($mimeType, 'opus') ? 'ogg' : 'mp4';

            // Step 2: Download binary audio
            $fileResponse = Http::withToken($this->token)->get($mediaUrl);
            if ($fileResponse->successful()) {
                $filename = 'audio/' . Str::uuid() . '.' . $ext;
                Storage::disk('public')->put($filename, $fileResponse->body());
                return Storage::disk('public')->url($filename);
            }
        } catch (\Throwable $e) {
            Log::error('Exception downloading WhatsApp media: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Send a text message to a WhatsApp user.
     */
    public function sendTextMessage(string $recipientPhone, string $message): bool
    {
        if (empty($this->token) || empty($this->phoneNumberId)) {
            Log::info("WHATSAPP_MOCK_OUTGOING to [{$recipientPhone}]: {$message}");
            return true;
        }

        try {
            $response = Http::withToken($this->token)
                ->post("https://graph.facebook.com/v20.0/{$this->phoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'to' => $recipientPhone,
                    'type' => 'text',
                    'text' => [
                        'body' => $message,
                    ],
                ]);

            return $response->successful();
        } catch (\Throwable $e) {
            Log::error('Failed to send WhatsApp message: ' . $e->getMessage());
            return false;
        }
    }
}
