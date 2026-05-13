<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class StateMachineTest extends TestCase
{
    public function test_global_cancel_returns_to_menu(): void
    {
        $t = StateMachine::transition(StateMachine::S_COLLECTING_ORDER_DETAILS, 'cancel_order');
        $this->assertSame(StateMachine::S_MENU, $t['next_step']);
        $this->assertSame('cancel_and_clear', $t['action']);
        $this->assertTrue($t['should_clear']);
    }

    public function test_global_reset_to_menu(): void
    {
        $t = StateMachine::transition(StateMachine::S_AWAITING_SLOT_SELECTION, 'reset_to_menu');
        $this->assertSame(StateMachine::S_MENU, $t['next_step']);
        $this->assertSame('show_menu', $t['action']);
    }

    public function test_menu_routes_to_check_customer(): void
    {
        $t = StateMachine::transition(StateMachine::S_MENU, 'order_gas');
        $this->assertSame(StateMachine::S_CHECKING_CUSTOMER, $t['next_step']);
    }

    public function test_menu_routes_to_general_help(): void
    {
        $t = StateMachine::transition(StateMachine::S_MENU, 'general_help');
        $this->assertSame(StateMachine::S_GENERAL_HELP, $t['next_step']);
        $this->assertSame('bridge_to_ghl', $t['action']);
    }

    public function test_repeat_order_skips_to_address_choice(): void
    {
        $t = StateMachine::transition(StateMachine::S_AWAITING_ORDER_CHOICE, 'repeat_order');
        $this->assertSame(StateMachine::S_AWAITING_ADDRESS_CHOICE, $t['next_step']);
        $this->assertSame('confirm_current_address', $t['action']);
    }

    public function test_new_order_goes_to_catalogue(): void
    {
        $t = StateMachine::transition(StateMachine::S_AWAITING_ORDER_CHOICE, 'new_order');
        $this->assertSame(StateMachine::S_SHOWING_PRODUCTS, $t['next_step']);
    }

    public function test_unclear_in_order_choice_returns_clarification(): void
    {
        $t = StateMachine::transition(StateMachine::S_AWAITING_ORDER_CHOICE, 'unclear');
        $this->assertSame(StateMachine::S_AWAITING_ORDER_CHOICE, $t['next_step']);
        $this->assertSame('clarify', $t['action']);
        $this->assertNotNull($t['response_template']);
    }

    public function test_confirm_new_details_has_no_duplicate_definition(): void
    {
        // BUG REGRESSION: n8n had `confirm_new_details` defined twice.
        // Ensure both Y and N transitions resolve correctly in our unified version.
        $defs = StateMachine::definition();
        $this->assertArrayHasKey(StateMachine::S_CONFIRM_NEW_DETAILS, $defs);
        $this->assertArrayHasKey('current_details', $defs[StateMachine::S_CONFIRM_NEW_DETAILS]);
        $this->assertArrayHasKey('new_details',     $defs[StateMachine::S_CONFIRM_NEW_DETAILS]);

        $y = StateMachine::transition(StateMachine::S_CONFIRM_NEW_DETAILS, 'current_details');
        $this->assertSame(StateMachine::S_SHOWING_PRODUCTS, $y['next_step']);

        $n = StateMachine::transition(StateMachine::S_CONFIRM_NEW_DETAILS, 'new_details');
        $this->assertSame(StateMachine::S_AWAITING_EXISTING_CUST_DETAILS, $n['next_step']);
    }

    public function test_address_choice_same(): void
    {
        $t = StateMachine::transition(StateMachine::S_AWAITING_ADDRESS_CHOICE, 'same_address');
        $this->assertSame(StateMachine::S_CHECKING_SLOTS, $t['next_step']);
        $this->assertSame('check_delivery_slots', $t['action']);
    }

    public function test_address_choice_different(): void
    {
        $t = StateMachine::transition(StateMachine::S_AWAITING_ADDRESS_CHOICE, 'different_address');
        $this->assertSame(StateMachine::S_AWAITING_NEW_ADDRESS, $t['next_step']);
        $this->assertSame('ask_for_address', $t['action']);
    }

    public function test_slot_letter_books_slot(): void
    {
        $t = StateMachine::transition(StateMachine::S_AWAITING_SLOT_SELECTION, 'awaiting_slot_selection_provided');
        $this->assertSame(StateMachine::S_BOOKING_SLOT, $t['next_step']);
        $this->assertSame('book_delivery_slot', $t['action']);
    }

    public function test_payment_p_starts_payment(): void
    {
        $t = StateMachine::transition(StateMachine::S_AWAITING_PAYMENT_CONFIRMATION, 'confirm_payment');
        $this->assertSame(StateMachine::S_PROCESSING_PAYMENT, $t['next_step']);
        $this->assertSame('process_payment', $t['action']);
    }

    public function test_payment_d_goes_to_new_order(): void
    {
        $t = StateMachine::transition(StateMachine::S_AWAITING_PAYMENT_CONFIRMATION, 'new_order');
        $this->assertSame(StateMachine::S_SHOWING_PRODUCTS, $t['next_step']);
    }

    public function test_unknown_state_falls_back_safely(): void
    {
        $t = StateMachine::transition('this_state_does_not_exist', 'whatever');
        $this->assertSame('this_state_does_not_exist', $t['next_step']);
        $this->assertSame('clarify', $t['action']);
    }
}
