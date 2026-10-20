<?php

return [
    'not_disputable' => 'Only a milestone that is funded or delivered can be disputed.',
    'closed' => 'This dispute is already closed.',
    'not_yours' => 'You cannot do this on this dispute.',
    'events' => [
        'opened' => 'Dispute opened.',
        'under_review' => 'An admin took the case.',
        'withdrawn' => 'The dispute was withdrawn.',
        'conceded' => 'The other side agreed. The case was settled.',
        'resolved' => 'An admin settled the case.',
        'appeal' => ['upheld' => 'The appeal was reviewed: the decision stands.', 'overturned' => 'The appeal was accepted: the decision was changed.'],
    ],
    'cannot_appeal' => 'You can appeal a settled dispute once, within :days days of the decision.',
    'appeal_decided' => 'This appeal was already decided.',
    'appeal_too_much' => 'The correction cannot be more than the milestone amount.',
];
