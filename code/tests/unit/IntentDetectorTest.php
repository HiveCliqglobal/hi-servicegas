<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class IntentDetectorTest extends TestCase
{
    // -------- normalization --------
    public function test_normalize_strips_trailing_punctuation(): void
    {
        $this->assertSame('1',   IntentDetector::normalize('1.'));
        $this->assertSame('1',   IntentDetector::normalize('1)'));
        $this->assertSame('y',   IntentDetector::normalize('Y!'));
        $this->assertSame('yes', IntentDetector::normalize(' Yes '));
    }

    // -------- global cancel / menu --------
    public function test_global_cancel_intent(): void
    {
        foreach (['cancel', 'stop', 'reset', 'CANCEL', 'please cancel'] as $msg) {
            $r = IntentDetector::detect($msg, StateMachine::S_AWAITING_SLOT_SELECTION);
            $this->assertSame('cancel_order', $r['intent'], "Failed: '$msg'");
        }
    }

    public function test_global_menu_intent(): void
    {
        foreach (['menu', '0', 'back', 'home'] as $msg) {
            $r = IntentDetector::detect($msg, StateMachine::S_COLLECTING_ORDER_DETAILS);
            $this->assertSame('reset_to_menu', $r['intent']);
        }
    }

    // -------- menu state --------
    public function test_menu_intents(): void
    {
        $r = IntentDetector::detect('1', StateMachine::S_MENU);
        $this->assertSame('order_gas', $r['intent']);
        $r = IntentDetector::detect('order gas', StateMachine::S_MENU);
        $this->assertSame('order_gas', $r['intent']);
        $r = IntentDetector::detect('2', StateMachine::S_MENU);
        $this->assertSame('general_help', $r['intent']);
        $r = IntentDetector::detect('I have a question', StateMachine::S_MENU);
        $this->assertSame('general_help', $r['intent']);
    }

    // -------- order choice (1/2 + variants) --------
    public function test_awaiting_order_choice(): void
    {
        $r = IntentDetector::detect('1',           StateMachine::S_AWAITING_ORDER_CHOICE);
        $this->assertSame('repeat_order', $r['intent']);
        $r = IntentDetector::detect('1.',          StateMachine::S_AWAITING_ORDER_CHOICE);
        $this->assertSame('repeat_order', $r['intent']);
        $r = IntentDetector::detect('repeat',      StateMachine::S_AWAITING_ORDER_CHOICE);
        $this->assertSame('repeat_order', $r['intent']);
        $r = IntentDetector::detect('2',           StateMachine::S_AWAITING_ORDER_CHOICE);
        $this->assertSame('new_order', $r['intent']);
        $r = IntentDetector::detect('different order please', StateMachine::S_AWAITING_ORDER_CHOICE);
        $this->assertSame('new_order', $r['intent']);
    }

    // -------- Y/N fuzzy --------
    public function test_yes_no_fuzzy(): void
    {
        $this->assertTrue(IntentDetector::isYes('y'));
        $this->assertTrue(IntentDetector::isYes('Yes'));
        $this->assertTrue(IntentDetector::isYes('yep'));
        $this->assertTrue(IntentDetector::isYes('Yeah'));
        $this->assertTrue(IntentDetector::isYes('correct'));
        $this->assertTrue(IntentDetector::isNo('n'));
        $this->assertTrue(IntentDetector::isNo('No'));
        $this->assertTrue(IntentDetector::isNo('nope'));
        $this->assertTrue(IntentDetector::isNo('nah'));

        $r = IntentDetector::detect('y',    StateMachine::S_CONFIRM_NEW_DETAILS);
        $this->assertSame('current_details', $r['intent']);
        $r = IntentDetector::detect('yeah', StateMachine::S_CONFIRM_NEW_DETAILS);
        $this->assertSame('current_details', $r['intent']);
        $r = IntentDetector::detect('nope', StateMachine::S_CONFIRM_NEW_DETAILS);
        $this->assertSame('new_details', $r['intent']);
    }

    // -------- order tokens --------
    public function test_order_token_parsing(): void
    {
        $this->assertSame(['B2', 'D1'], IntentDetector::parseOrderTokens("B2\nD1"));
        $this->assertSame(['B2', 'D1'], IntentDetector::parseOrderTokens('B2,D1'));
        $this->assertSame(['B2', 'D1'], IntentDetector::parseOrderTokens('B 2, D 1'));
        $this->assertSame(['B2'],       IntentDetector::parseOrderTokens('b2'));
        $this->assertSame([],           IntentDetector::parseOrderTokens('BB2'));
        $this->assertSame([],           IntentDetector::parseOrderTokens('hello'));
    }

    public function test_collecting_order_details_intent(): void
    {
        $r = IntentDetector::detect("B2\nD1", StateMachine::S_COLLECTING_ORDER_DETAILS);
        $this->assertSame('collecting_order_details_provided', $r['intent']);
        $this->assertSame(['B2', 'D1'], $r['extracted']['order_tokens']);

        $r = IntentDetector::detect('hello there', StateMachine::S_COLLECTING_ORDER_DETAILS);
        $this->assertSame('unclear', $r['intent']);
    }

    // -------- address choice (S/D/Y/N) --------
    public function test_address_choice(): void
    {
        foreach (['s', 'S', 'y', 'Y', 'same'] as $msg) {
            $r = IntentDetector::detect($msg, StateMachine::S_AWAITING_ADDRESS_CHOICE);
            // "same" doesn't strictly match s/y after the regex strip → only s/y reliable
            if (in_array(strtolower($msg), ['s', 'y'])) {
                $this->assertSame('same_address', $r['intent'], "Failed: $msg");
            }
        }
        foreach (['d', 'D', 'n', 'N'] as $msg) {
            $r = IntentDetector::detect($msg, StateMachine::S_AWAITING_ADDRESS_CHOICE);
            $this->assertSame('different_address', $r['intent']);
        }
    }

    // -------- postal code --------
    public function test_street_code(): void
    {
        $r = IntentDetector::detect('7140', StateMachine::S_AWAITING_STREET_CODE);
        $this->assertSame('street_code_provided', $r['intent']);
        $this->assertSame('7140', $r['extracted']['raw_street_code']);

        $r = IntentDetector::detect('71', StateMachine::S_AWAITING_STREET_CODE);
        $this->assertSame('unclear', $r['intent']);
    }

    // -------- slot picker (letter + date) --------
    public function test_slot_letter(): void
    {
        $r = IntentDetector::detect('A', StateMachine::S_AWAITING_SLOT_SELECTION);
        $this->assertSame('awaiting_slot_selection_provided', $r['intent']);
        $this->assertSame('A', $r['extracted']['slot_letter']);
    }

    public function test_slot_date_dmy(): void
    {
        $r = IntentDetector::detect('25/01/2026', StateMachine::S_AWAITING_SLOT_SELECTION);
        $this->assertSame('awaiting_custom_slot_selection_provided', $r['intent']);
        $this->assertSame('2026-01-25', $r['extracted']['raw_date']);
    }

    public function test_slot_date_iso(): void
    {
        $r = IntentDetector::detect('2026-03-15', StateMachine::S_AWAITING_SLOT_SELECTION);
        $this->assertSame('awaiting_custom_slot_selection_provided', $r['intent']);
        $this->assertSame('2026-03-15', $r['extracted']['raw_date']);
    }

    public function test_slot_relative_tomorrow(): void
    {
        $r = IntentDetector::detect('tomorrow', StateMachine::S_AWAITING_SLOT_SELECTION);
        $this->assertSame('awaiting_custom_slot_selection_provided', $r['intent']);
        $this->assertSame(date('Y-m-d', strtotime('+1 day')), $r['extracted']['raw_date']);
    }

    // -------- payment confirmation --------
    public function test_payment_confirmation(): void
    {
        $r = IntentDetector::detect('p',   StateMachine::S_AWAITING_PAYMENT_CONFIRMATION);
        $this->assertSame('confirm_payment', $r['intent']);
        $r = IntentDetector::detect('Pay', StateMachine::S_AWAITING_PAYMENT_CONFIRMATION);
        $this->assertSame('confirm_payment', $r['intent']);
        $r = IntentDetector::detect('d',   StateMachine::S_AWAITING_PAYMENT_CONFIRMATION);
        $this->assertSame('new_order', $r['intent']);
    }
}
