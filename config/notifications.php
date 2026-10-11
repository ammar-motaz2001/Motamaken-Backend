<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Notification groups ("Email & Notification" settings)
    |--------------------------------------------------------------------------
    |
    | Every bell notification belongs to a group by the start of its type. The bell always gets everything; a group is
    | also emailed when the user turned it on (or by the default below when they never chose). Account notices
    | (identity checks, security) are always emailed.
    |
    */

    'groups' => [
        'messages' => ['conversation.', 'message.'],
        'proposals' => ['proposal.', 'team.'],
        'projects' => ['escrow.', 'milestone.', 'review.'],
        'payments' => ['deposit.', 'withdrawal.', 'promotion.', 'subscription.', 'affiliate.'],
        'disputes' => ['dispute.'],
        'account' => ['kyc.', 'security.'],
        'rewards' => ['points.', 'medal.', 'draw.'],
    ],

    'defaults' => [
        'messages' => false,
        'proposals' => true,
        'projects' => true,
        'payments' => true,
        'disputes' => true,
        'account' => true,
        'rewards' => false,
    ],

    // Groups the user cannot turn off.
    'required' => ['account'],

];
