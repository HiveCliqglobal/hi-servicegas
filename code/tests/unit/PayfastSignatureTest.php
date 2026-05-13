<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class PayfastSignatureTest extends TestCase
{
    public function test_signature_deterministic_with_fixed_input(): void
    {
        $data = [
            'merchant_id'   => '10000100',
            'merchant_key'  => '46f0cd694581a',
            'return_url'    => 'https://example.com/success',
            'cancel_url'    => 'https://example.com/cancel',
            'notify_url'    => 'https://example.com/itn',
            'name_first'    => 'James',
            'name_last'     => 'Elliot',
            'email_address' => 'james@example.com',
            'cell_number'   => '27848580000',
            'm_payment_id'  => 'ORD-001',
            'amount'        => '385.00',
            'item_name'     => 'Gas Delivery Order',
            'item_description' => 'Order ORD-001',
        ];
        $sig1 = payfast_signature($data, 'jt7NOE43FZPn');
        $sig2 = payfast_signature($data, 'jt7NOE43FZPn');
        $this->assertSame($sig1, $sig2, 'Signature must be deterministic');
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $sig1, 'Must be 32-char hex MD5');
    }

    public function test_signature_changes_with_different_passphrase(): void
    {
        $data = ['merchant_id' => '10000100', 'merchant_key' => 'x', 'amount' => '100.00', 'item_name' => 'X'];
        $a = payfast_signature($data, 'pass-A');
        $b = payfast_signature($data, 'pass-B');
        $this->assertNotSame($a, $b);
    }

    public function test_signature_excludes_empty_fields(): void
    {
        $base = ['merchant_id' => '10000100', 'merchant_key' => 'x', 'amount' => '100.00', 'item_name' => 'X'];
        $a = payfast_signature($base, '');
        $b = payfast_signature($base + ['name_first' => '', 'name_last' => ''], '');
        $this->assertSame($a, $b, 'Empty fields must not affect signature');
    }
}
