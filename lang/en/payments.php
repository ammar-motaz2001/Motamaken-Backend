<?php

return [
    'insufficient_funds' => 'Your wallet balance is not enough for this.',
    'kyc_required' => 'Verify your identity before withdrawing money.',
    'amount_too_small' => 'The minimum is :min :currency.',
    'amount_too_large' => 'The maximum is :max :currency.',
    'deposit_not_pending' => 'This payment was already completed or cancelled.',
    'withdrawal_not_pending' => 'This withdrawal was already answered or cancelled.',
    'bad_signature' => 'Invalid signature.',
    'unsupported' => 'This payment method is not available.',

    'escrow_exists' => 'Payment protection is already set up for this project.',
    'escrow_needs_hired' => 'Hire someone first: accept an offer before setting up payments.',
    'escrow_missing' => 'No payment protection is set up for this project yet.',
    'milestones_total' => 'The milestones must add up to the offer: :total :currency.',
    'milestone_not_pending' => 'This milestone is already funded.',
    'milestone_not_funded' => 'Fund this milestone before it can be delivered.',
    'milestone_not_submitted' => 'The work was not delivered yet.',
    'milestone_wrong_state' => 'This milestone cannot be changed in its current state.',
    'escrow_has_funds' => 'Funds are already in escrow. Open a dispute to cancel.',
    'escrow_incomplete' => 'Release all milestones before completing the project.',

    'types' => [
        'deposit' => 'Deposit',
        'withdrawal_request' => 'Withdrawal request',
        'withdrawal_cancelled' => 'Withdrawal cancelled',
        'withdrawal_rejected' => 'Withdrawal rejected',
        'escrow_fund' => 'Paid into escrow: :milestone (:project)',
        'escrow_release' => 'Payment received: :milestone (:project)',
        'dispute_settlement' => 'Dispute settled: :milestone (:project)',
        'subscription' => 'Subscription',
        'promotion' => 'Promotion',
        'affiliate_commission' => 'Affiliate commission',
        'campaign_funding' => 'Campaign budget',
        'campaign_earning' => 'Promotion earnings',
        'adjustment' => 'Adjustment by support',
    ],
];
