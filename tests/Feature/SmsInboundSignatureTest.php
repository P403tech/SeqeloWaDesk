<?php

namespace Tests\Feature;

use App\Models\WaProviderConfig;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class SmsInboundSignatureTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);

        Schema::dropIfExists('wa_provider_configs');
        Schema::create('wa_provider_configs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->nullable();
            $table->string('provider');
            $table->string('status')->nullable();
            $table->string('phone_number')->nullable();
            $table->text('credentials_json')->nullable();
            $table->text('meta_json')->nullable();
            $table->timestamps();
        });
    }

    public function test_twilio_inbound_without_signature_is_rejected(): void
    {
        $this->sender('twilio', '+15551212', 'auth-token');

        $this->post('/api/sms/inbound', [
            'To' => '+15551212',
            'From' => '+19998887',
            'Body' => 'hello',
        ])->assertStatus(403);
    }

    public function test_twilio_inbound_with_valid_signature_is_accepted(): void
    {
        $token = 'auth-token';
        $this->sender('twilio', '+15551212', $token);

        $params = [
            'To' => '+15551212',
            'From' => '+19998887',
            'Body' => '',
        ];
        ksort($params);
        $url = 'https://localhost/api/sms/inbound';
        $data = $url;
        foreach ($params as $k => $v) {
            $data .= $k.$v;
        }
        $sig = base64_encode(hash_hmac('sha1', $data, $token, true));

        $this->post('/api/sms/inbound', $params, ['X-Twilio-Signature' => $sig])
            ->assertOk();
    }

    public function test_msg91_inbound_does_not_require_a_twilio_signature(): void
    {
        $this->sender('msg91', '+15551212', 'msg91-key');

        $this->post('/api/sms/inbound', [
            'To' => '+15551212',
            'From' => '+19998887',
            'Body' => '',
        ])->assertOk();
    }

    private function sender(string $provider, string $number, string $token): void
    {
        $row = new WaProviderConfig();
        $row->workspace_id = 1;
        $row->provider = 'sms';
        $row->status = WaProviderConfig::STATUS_CONNECTED;
        $row->phone_number = $number;
        $row->meta_json = ['sms_provider' => $provider];
        $row->setCreds(['account_sid' => 'AC123', 'auth_token' => $token]);
        $row->save();
    }
}
