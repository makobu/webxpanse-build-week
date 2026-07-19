<?php

namespace CRM\Tests\Unit\Services;

use CRM\Services\MpesaDarajaGateway;
use PHPUnit\Framework\TestCase;

class MpesaDarajaGatewayTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $_ENV['MPESA_FAKE_MODE'] = 'true';
        $_ENV['MPESA_FAKE_STK_RESPONSE_CODE'] = '0';
        $_ENV['MPESA_FAKE_QUERY_RESULT_CODE'] = '0';
        $_ENV['MPESA_FAKE_RECEIPT_NUMBER'] = 'FAKE12345';
        putenv('MPESA_FAKE_MODE=true');
        putenv('MPESA_FAKE_STK_RESPONSE_CODE=0');
        putenv('MPESA_FAKE_QUERY_RESULT_CODE=0');
        putenv('MPESA_FAKE_RECEIPT_NUMBER=FAKE12345');
    }

    protected function tearDown(): void
    {
        putenv('MPESA_FAKE_MODE');
        putenv('MPESA_FAKE_STK_RESPONSE_CODE');
        putenv('MPESA_FAKE_QUERY_RESULT_CODE');
        putenv('MPESA_FAKE_RECEIPT_NUMBER');
        unset(
            $_ENV['MPESA_FAKE_MODE'],
            $_ENV['MPESA_FAKE_STK_RESPONSE_CODE'],
            $_ENV['MPESA_FAKE_QUERY_RESULT_CODE'],
            $_ENV['MPESA_FAKE_RECEIPT_NUMBER']
        );

        parent::tearDown();
    }

    public function testFakeModeNormalizesPhoneStartsStkPushAndQueriesReceipt(): void
    {
        $gateway = new MpesaDarajaGateway([
            'enabled' => true,
            'fake_mode' => true,
            'environment' => 'sandbox',
            'business_short_code' => '174379',
            'passkey' => 'test-passkey',
            'callback_url' => 'https://crm.example/api/webhooks/mpesa.php',
        ]);

        $this->assertTrue($gateway->isConfigured());
        $this->assertSame('254712345678', $gateway->normalizePhoneNumber('0712 345 678'));

        $stk = $gateway->stkPush('0712345678', 1000.25, 'saas_reference', 'CRM token pack');
        $this->assertSame('0', (string) ($stk['ResponseCode'] ?? ''));
        $this->assertStringStartsWith('ws_FAKE_', (string) ($stk['CheckoutRequestID'] ?? ''));

        $query = $gateway->queryStkPush((string) ($stk['CheckoutRequestID'] ?? ''));
        $this->assertSame('0', (string) ($query['ResultCode'] ?? ''));
        $this->assertSame('FAKE12345', (string) ($query['CallbackMetadata']['Item'][1]['Value'] ?? ''));
    }
}
