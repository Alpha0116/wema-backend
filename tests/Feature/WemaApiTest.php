<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Merchant;
use App\Models\Product;
use App\Models\Transaction;
use Database\Seeders\WemaDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WemaApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(WemaDemoSeeder::class);
    }

    /**
     * Test Écran 1: Dashboard Overview KPIs and Activity.
     */
    public function test_dashboard_overview_returns_valid_kpis(): void
    {
        $response = $this->getJson('/api/dashboard/overview');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'merchant' => ['id', 'name', 'channel_user_id'],
                'kpis' => ['daily_sales', 'daily_collected', 'total_debts', 'pending_validations'],
                'alerts',
                'recent_activity',
            ]);

        $data = $response->json();
        $this->assertGreaterThanOrEqual(0, $data['kpis']['total_debts']);
        $this->assertNotEmpty($data['recent_activity']);
    }

    /**
     * Test Écran 2: Transactions Listing and Filtering.
     */
    public function test_transactions_listing_and_filters(): void
    {
        $response = $this->getJson('/api/transactions');
        $response->assertStatus(200)
            ->assertJsonStructure(['data', 'current_page', 'total']);

        // Filter by status PENDING
        $pendingResponse = $this->getJson('/api/transactions?status=PENDING');
        $pendingResponse->assertStatus(200);
        foreach ($pendingResponse->json('data') as $item) {
            $this->assertEquals('PENDING', $item['status']);
        }
    }

    /**
     * Test Écran 2: Confirmation of Pending Transaction.
     */
    public function test_confirm_pending_transaction(): void
    {
        $pendingTx = Transaction::where('status', 'PENDING')->first();
        $this->assertNotNull($pendingTx);

        $response = $this->postJson("/api/transactions/{$pendingTx->id}/confirm");

        $response->assertStatus(200)
            ->assertJsonPath('transaction.status', 'CONFIRMED');

        $this->assertEquals('CONFIRMED', $pendingTx->fresh()->status);
    }

    /**
     * Test Écran 3: Customers sorted by balance descending.
     */
    public function test_customers_list_ordered_by_debt(): void
    {
        $response = $this->getJson('/api/customers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'summary' => ['total_customers', 'debtors_count', 'total_outstanding_debt'],
                'data',
            ]);

        $customers = $response->json('data');
        $this->assertNotEmpty($customers);

        // Verify descending order of balance
        for ($i = 0; $i < count($customers) - 1; $i++) {
            $this->assertGreaterThanOrEqual($customers[$i + 1]['balance'], $customers[$i]['balance']);
        }
    }

    /**
     * Test Écran 4: Products and Stock indicators.
     */
    public function test_products_list_with_stock_status(): void
    {
        $response = $this->getJson('/api/products');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'summary' => ['total_products', 'low_stock_count', 'out_of_stock_count', 'low_stock_threshold'],
                'data',
            ]);

        $products = $response->json('data');
        $this->assertNotEmpty($products);
    }

    /**
     * Test Speech / Voice Simulation pipeline.
     */
    public function test_voice_simulation_pipeline(): void
    {
        $payload = [
            'text' => "J'ai vendu 2 bidons d'huile à Maman Chantal, elle a payé 4000 francs.",
            'confidence' => 0.95,
        ];

        $response = $this->postJson('/api/simulate-voice', $payload);

        $response->assertStatus(200)
            ->assertJsonStructure([
                'simulation_success',
                'transcript',
                'status',
                'confidence',
                'extracted',
                'confirmation_message',
                'transaction',
            ]);

        $this->assertEquals('CONFIRMED', $response->json('status'));
    }

    /**
     * Test WhatsApp Webhook Verification Handshake.
     */
    public function test_whatsapp_webhook_verification(): void
    {
        $response = $this->get('/api/webhook/whatsapp?hub_mode=subscribe&hub_verify_token=wema_secret_verify_token_2026&hub_challenge=1158201444');

        $response->assertStatus(200);
        $this->assertEquals('1158201444', $response->getContent());
    }

    /**
     * Test Swagger / OpenAPI Documentation UI.
     */
    public function test_docs_api_ui_is_accessible(): void
    {
        $response = $this->get('/docs/api');
        $response->assertStatus(200);

        $jsonResponse = $this->get('/docs/api.json');
        $jsonResponse->assertStatus(200);
    }
}
