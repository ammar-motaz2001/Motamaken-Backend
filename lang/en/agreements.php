<?php

// Changing or cancelling an accepted offer by agreement (App\Services\AgreementService).
return [
    'already_pending' => 'There is already an open request on this project. Answer or withdraw it first.',
    'dispute_open' => 'A dispute is open on this project. It must be settled first.',
    'nothing_changes' => 'Change the price or the delivery time.',
    'price_locked' => 'The price cannot change once money is in escrow. You can change the delivery time, or cancel by agreement.',
    'not_in_progress' => 'Only an accepted offer on a project in progress can be changed or cancelled.',
    'own_request' => 'The other side answers this request.',
    'not_pending' => 'This request was already answered.',
];
