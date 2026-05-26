<?php
/**
 * state_machine.php — Hi-Service conversation FSM.
 *
 * Ported from the n8n "State Machine Controller" code node.
 * Bugs fixed during port:
 *   1. Removed duplicate `confirm_new_details` definition (n8n had two)
 *   2. Replaced daily session reset with 24h rolling expiry (handled in sessions table)
 *   3. Added STATE_MENU as default entry state
 *   4. Optional chaining everywhere addresses are read
 *   5. Global try/catch in caller (conversation.php) wraps the brain
 *
 * Used by BOTH the WhatsApp webhook and the web shop. Web shop calls
 * `transition()` directly without going through the WhatsApp intent layer.
 *
 * Hi-Service Chatbot · v1
 */

declare(strict_types=1);

final class StateMachine
{
    // -------- States --------
    public const S_MENU                          = 'menu';
    public const S_INITIAL                       = 'initial';
    public const S_CHECKING_CUSTOMER             = 'checking_customer';
    public const S_AWAITING_ORDER_CHOICE         = 'awaiting_order_choice';
    public const S_NEW_ORDER_CLARIFICATION       = 'new_order_clarification';
    public const S_CONFIRM_NEW_DETAILS           = 'confirm_new_details';
    public const S_AWAITING_NEW_CUSTOMER_DETAILS = 'awaiting_new_customer_details';
    public const S_AWAITING_EXISTING_CUST_DETAILS= 'awaiting_existing_customer_details';
    public const S_AWAITING_STREET_CODE          = 'awaiting_street_code';
    public const S_SHOWING_PRODUCTS              = 'showing_products';
    public const S_COLLECTING_ORDER_DETAILS      = 'collecting_order_details';
    public const S_AWAITING_ADDRESS_CHOICE       = 'awaiting_address_choice';
    public const S_AWAITING_NEW_ADDRESS          = 'awaiting_new_address';
    public const S_CHECKING_SLOTS                = 'checking_slots';
    public const S_AWAITING_SLOT_SELECTION       = 'awaiting_slot_selection';
    public const S_CHECKING_CUSTOM_SLOT          = 'checking_custom_slot';
    public const S_BOOKING_SLOT                  = 'booking_slot';
    public const S_AWAITING_PAYMENT_CONFIRMATION = 'awaiting_payment_confirmation';
    public const S_PROCESSING_PAYMENT            = 'processing_payment';
    public const S_CANCELLED                     = 'cancelled';
    public const S_GENERAL_HELP                  = 'general_help';
    public const S_OUT_OF_STOCK                  = 'out_of_stock';
    public const S_AWAITING_CALLBACK_DETAILS     = 'awaiting_callback_details';

    /**
     * Compute the next transition for (currentStep, intent).
     *
     * @return array{next_step:string, action:string, response_template:?string}
     */
    public static function transition(string $currentStep, string $intent): array
    {
        // Global cancel/reset — works from any state
        if ($intent === 'reset' || $intent === 'cancel_order') {
            return [
                'next_step'         => self::S_MENU,
                'action'            => 'cancel_and_clear',
                'response_template' => null,
                'should_clear'      => true,
            ];
        }

        // Global menu — return to top
        if ($intent === 'reset_to_menu') {
            return [
                'next_step'         => self::S_MENU,
                'action'            => 'show_menu',
                'response_template' => null,
                'should_clear'      => false,
            ];
        }

        $stateMachine = self::definition();
        $stepTransitions = $stateMachine[$currentStep] ?? null;

        if (!$stepTransitions) {
            return [
                'next_step'         => $currentStep,
                'action'            => 'clarify',
                'response_template' => null,
                'should_clear'      => false,
            ];
        }

        $t = $stepTransitions[$intent] ?? $stepTransitions['_default'] ?? null;
        if (!$t) {
            return [
                'next_step'         => $currentStep,
                'action'            => 'clarify',
                'response_template' => null,
                'should_clear'      => false,
            ];
        }
        $t['should_clear'] = false;
        return $t;
    }

    /**
     * FSM definition — single source of truth.
     */
    public static function definition(): array
    {
        return [

            // ENTRY — show menu by default
            self::S_MENU => [
                'order_gas'    => ['next_step' => self::S_CHECKING_CUSTOMER, 'action' => 'check_customer',            'response_template' => null],
                'general_help' => ['next_step' => self::S_GENERAL_HELP,      'action' => 'bridge_to_ghl',             'response_template' => null],
                '_default'     => ['next_step' => self::S_MENU,              'action' => 'show_menu',                 'response_template' => null],
            ],

            self::S_INITIAL => [
                '_default' => ['next_step' => self::S_CHECKING_CUSTOMER, 'action' => 'check_customer', 'response_template' => null],
            ],

            // After customer lookup, awaiting choice (returning customers)
            self::S_AWAITING_ORDER_CHOICE => [
                'repeat_order' => ['next_step' => self::S_AWAITING_ADDRESS_CHOICE, 'action' => 'confirm_current_address', 'response_template' => null],
                'new_order'    => ['next_step' => self::S_SHOWING_PRODUCTS,        'action' => 'get_product_catalog',     'response_template' => null],
                '_default'     => ['next_step' => self::S_AWAITING_ORDER_CHOICE,   'action' => 'clarify', 'response_template' =>
                    "Please reply with:\n1 - to repeat your last order\n2 - to place a different order"],
            ],

            self::S_NEW_ORDER_CLARIFICATION => [
                'keep_order' => ['next_step' => self::S_AWAITING_ADDRESS_CHOICE, 'action' => 'confirm_current_address', 'response_template' => null],
                'new_order'  => ['next_step' => self::S_SHOWING_PRODUCTS,        'action' => 'get_product_catalog',     'response_template' => null],
                '_default'   => ['next_step' => self::S_NEW_ORDER_CLARIFICATION, 'action' => 'clarify', 'response_template' =>
                    "Please reply with:\n1 - to keep your previous order\n2 - to start a new order"],
            ],

            // Collect product letters: B2 D1
            self::S_COLLECTING_ORDER_DETAILS => [
                'collecting_order_details_provided' => [
                    'next_step' => self::S_COLLECTING_ORDER_DETAILS, 'action' => 'collecting_order_details', 'response_template' => null,
                ],
                '_default' => [
                    'next_step' => self::S_COLLECTING_ORDER_DETAILS, 'action' => 'clarify',
                    'response_template' =>
                        "Please provide your order details clearly. Reply with the bold Letter in front of the item followed by the quantity.\n\n" .
                        "*Example:*\nB2\n(B2 = 2 x 5kg LPG Gas Delivered - Incl. Exchange Cylinder @ R223.00)\n",
                ],
            ],

            // Confirm-details prompt — UNIFIED (bug fix: n8n had two definitions)
            self::S_CONFIRM_NEW_DETAILS => [
                'current_details' => ['next_step' => self::S_SHOWING_PRODUCTS,                'action' => 'get_product_catalog',          'response_template' => null],
                'new_details'     => ['next_step' => self::S_AWAITING_EXISTING_CUST_DETAILS,  'action' => 'awaiting_existing_customer_details',
                                      'response_template' => 'Please provide your updated customer details.'],
                '_default'        => ['next_step' => self::S_CONFIRM_NEW_DETAILS,             'action' => 'clarify',
                                      'response_template' => "*Please reply with:*\nY - to keep your current details\nN - to update your details"],
            ],

            self::S_AWAITING_NEW_CUSTOMER_DETAILS => [
                'new_customer_details_provided' => [
                    'next_step' => self::S_CONFIRM_NEW_DETAILS, 'action' => 'awaiting_new_customer_details', 'response_template' => null,
                ],
                '_default' => [
                    'next_step' => self::S_CONFIRM_NEW_DETAILS, 'action' => 'clarify',
                    'response_template' =>
                        "I did not get that in the expected format.\n\n" .
                        "*Please provide the following, 1 below the other:*\n" .
                        "1. Name and Surname\n2. Street address, Suburb, City, Street/Postal code\n3. Email Address\n\n" .
                        "*Example:*\nJames Elliot\n31 Example Road, Strand, Cape Town, 7140\njames.elliot@gmail.com\n\n" .
                        "*Reply with:*\nR - to recheck your current details\n",
                ],
            ],

            self::S_AWAITING_EXISTING_CUST_DETAILS => [
                'recheck_current_details' => [
                    'next_step' => 'recheck_current_details', 'action' => 'recheck_current_details', 'response_template' => null,
                ],
                'existing_customer_details_provided' => [
                    'next_step' => 'update_customer_details', 'action' => 'update_customer_details', 'response_template' => null,
                ],
                '_default' => [
                    'next_step' => self::S_AWAITING_EXISTING_CUST_DETAILS, 'action' => 'clarify',
                    'response_template' =>
                        "I did not get that in the expected format.\n\n" .
                        "*Please provide the following, 1 below the other:*\n" .
                        "1. Name and Surname\n2. Street address, Suburb, City, Street/Postal code\n3. Email Address (optional)\n\n" .
                        "*Example:*\nJames Elliot\n31 Example Road, Strand, Cape Town, 7140\njames.elliot@gmail.com\n\n" .
                        "*Reply with:*\nR - to recheck your current details\n",
                ],
            ],

            self::S_AWAITING_ADDRESS_CHOICE => [
                'same_address'      => ['next_step' => self::S_CHECKING_SLOTS,      'action' => 'check_delivery_slots',
                                        'response_template' => 'Got it. Checking delivery times...'],
                'different_address' => ['next_step' => self::S_AWAITING_NEW_ADDRESS,'action' => 'ask_for_address',
                                        'response_template' => "What's your new delivery address?\n\nPlease include: Street, Suburb, City, Postal code\n"],
                '_default'          => ['next_step' => self::S_AWAITING_ADDRESS_CHOICE, 'action' => 'clarify',
                                        'response_template' => "Please reply with:\nS/Y - same address\nD/N - different address"],
            ],

            self::S_AWAITING_NEW_ADDRESS => [
                'confirm_current_address' => [
                    'next_step' => 'confirm_current_address', 'action' => 'confirm_current_address', 'response_template' => null,
                ],
                'address_provided' => [
                    'next_step' => self::S_AWAITING_ADDRESS_CHOICE, 'action' => 'awaiting_new_address',
                    'response_template' => 'Got it. Checking delivery times...',
                ],
                '_default' => [
                    'next_step' => self::S_AWAITING_NEW_ADDRESS, 'action' => 'clarify',
                    'response_template' =>
                        "I did not get that in the expected format.\n\n" .
                        "*Please provide the following, 1 below the other:*\n" .
                        "1. Street address\n2. Suburb\n3. City\n4. Postal code\n\n" .
                        "*Example:*\n31 Example Road\nStrand\nCape Town\n7140\n\n" .
                        "*Reply with:*\nR - to recheck your current details\n",
                ],
            ],

            self::S_AWAITING_STREET_CODE => [
                'street_code_provided' => [
                    'next_step' => self::S_AWAITING_NEW_CUSTOMER_DETAILS, 'action' => 'awaiting_street_code', 'response_template' => '',
                ],
                '_default' => [
                    'next_step' => self::S_AWAITING_STREET_CODE, 'action' => 'clarify',
                    'response_template' => "I did not get that in the expected format. Please enter your 4-digit postal code.\n\n*Example:*\n7140\n",
                ],
            ],

            self::S_AWAITING_SLOT_SELECTION => [
                'awaiting_slot_selection_provided' => [
                    'next_step' => self::S_BOOKING_SLOT, 'action' => 'book_delivery_slot',
                    'response_template' => 'Perfect! Booking your delivery…',
                ],
                'awaiting_custom_slot_selection_provided' => [
                    'next_step' => self::S_CHECKING_CUSTOM_SLOT, 'action' => 'check_custom_slot',
                    'response_template' => 'Got it! Checking availability for that date…',
                ],
                'unclear' => [
                    'next_step' => self::S_AWAITING_SLOT_SELECTION, 'action' => 'clarify',
                    'response_template' => 'Please select A or B for your preferred delivery slot, or type a date (e.g. 23/02/2026).',
                ],
                '_default' => [
                    'next_step' => self::S_AWAITING_SLOT_SELECTION, 'action' => 'clarify',
                    'response_template' => 'Please select A or B for your preferred delivery slot, or type a date (e.g. 23/02/2026).',
                ],
            ],

            // ════════════ Out-of-stock handling ════════════
            // Entered when actCollectOrderDetails detects a tracked product in the
            // customer's cart has insufficient stock. The customer gets 3 clear options
            // (try a different product, leave their number, or cancel) so they never
            // end up in a dead-end "your order is locked but unfulfillable" state.
            self::S_OUT_OF_STOCK => [
                'try_other_product' => ['next_step' => self::S_SHOWING_PRODUCTS,          'action' => 'get_product_catalog',         'response_template' => null],
                'request_callback'  => ['next_step' => self::S_AWAITING_CALLBACK_DETAILS, 'action' => 'request_callback_details',    'response_template' => null],
                '_default'          => ['next_step' => self::S_OUT_OF_STOCK,              'action' => 'clarify',
                                        'response_template' => "Please reply with:\n*A* - try a different product\n*B* - leave your number, we'll call when it's back in stock\n*Cancel* - cancel this order"],
            ],

            // Customer sent "B" at out-of-stock → we ask for their name + phone confirmation
            self::S_AWAITING_CALLBACK_DETAILS => [
                'callback_details_provided' => ['next_step' => self::S_MENU,                       'action' => 'log_callback_lead',  'response_template' => null],
                '_default'                  => ['next_step' => self::S_AWAITING_CALLBACK_DETAILS,  'action' => 'clarify',
                                                'response_template' => "Please send your *name* (we already have your number from this chat). Our team will reach out as soon as stock arrives.\n\n*Example:*\nShawn Lochner\n\nOr type *Cancel* to drop this."],
            ],

            self::S_AWAITING_PAYMENT_CONFIRMATION => [
                'confirm_payment' => [
                    'next_step' => self::S_PROCESSING_PAYMENT, 'action' => 'process_payment',
                    'response_template' => 'Perfect! Processing your payment link... 💳',
                ],
                'new_order' => [
                    'next_step' => self::S_SHOWING_PRODUCTS, 'action' => 'get_product_catalog', 'response_template' => null,
                ],
                'cancel_order' => [
                    'next_step' => self::S_CANCELLED, 'action' => 'cancel_and_clear',
                    'response_template' => 'Your order has been cancelled. Hope to see you again soon! 👋',
                ],
                '_default' => [
                    'next_step' => self::S_AWAITING_PAYMENT_CONFIRMATION, 'action' => 'clarify',
                    'response_template' => "Please reply with:\nP - to continue to payment\nD - to place a different order\nCancel - to cancel your order",
                ],
            ],
        ];
    }
}
