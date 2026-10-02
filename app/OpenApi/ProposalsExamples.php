<?php

namespace App\OpenApi;

/**
 * Swagger examples for posting projects, sending offers and the profile. See ApiExamples for how they are applied.
 */
class ProposalsExamples
{
    /**
     * @return array<string, array{request?: array, requestJson?: array, requestMultipart?: array, responses?: array<int, array{0: string, 1: array}|null>}>
     */
    public static function operations(): array
    {
        $web = HomepageExamples::category(1, 'website-it-software', 'Website, IT & Software', 'laptop');
        $instagram = HomepageExamples::category(7, 'instagram', 'Instagram', 'instagram', 'influencer');

        $project = HomepageExamples::project(43, 'Landing page for a dental clinic', $web, 7, 'Abdulrahman Al-Ghamdi', 300, 600);
        $mine = [...$project, 'proposalsCount' => 3];
        $inProgress = [...$project, 'status' => 'in_progress', 'acceptedProposalId' => 10];
        $request = HomepageExamples::project(44, 'Promote our new coffee shop', $instagram, 7, 'Abdulrahman Al-Ghamdi', 150, 400, 'influencer_request', 'Saudi Arabia', 'Riyadh');

        $offer = self::proposal(10, 43, 420, 6, 'pending');
        $accepted = self::proposal(10, 43, 420, 6, 'accepted');
        $withMine = [...$project, 'myProposal' => $offer];

        $forbidden = [403 => ['Not allowed for this user', ['Forbidden' => ['message' => 'This action is unauthorized.']]]];
        $notFound = [404 => ['Not found', ['Not found' => ['message' => 'No query results for model [App\\Models\\Project] 999']]]];
        $rate = [429 => ['Too many requests', ['Rate limited' => ['message' => 'Too many attempts. Please try again in a minute.']]]];
        $notPending = [422 => ['The offer was already answered or withdrawn', [
            'Already answered' => ['message' => 'This offer was already answered or withdrawn.', 'code' => 'proposal_not_pending'],
        ]]];
        $unauthenticated = ApiExamples::unauthenticated();

        return [
            'GET /projects/{project}' => [
                'responses' => [
                    200 => ['The project. `myProposal` appears for a logged-in visitor who is not the owner (null when they have no offer yet)', [
                        'Visitor' => ['data' => $project],
                        'Logged in, offer already sent' => ['data' => $withMine],
                    ]],
                    404 => ['Unknown, or not open and not yours', ['Not found' => ['message' => 'No query results for model [App\\Models\\Project] 999']]],
                ],
            ],

            'POST /projects' => [
                'request' => [
                    'Project for freelancers' => [
                        'type' => 'project', 'categoryId' => 1, 'title' => 'Landing page for a dental clinic',
                        'description' => 'A one page website in Arabic and English with booking form and WhatsApp button.',
                        'budgetMin' => 300, 'budgetMax' => 600, 'currency' => 'USD', 'countryId' => 1, 'cityId' => 1,
                    ],
                    'Request for influencers' => [
                        'type' => 'influencer_request', 'categoryId' => 7, 'title' => 'Promote our new coffee shop',
                        'description' => 'One reel and three stories showing the new menu, with the shop tagged.',
                        'budgetMin' => 150, 'budgetMax' => 400,
                    ],
                ],
                'responses' => [
                    201 => ['Posted: public right away', ['Project' => ['data' => $project], 'Influencer request' => ['data' => $request]]],
                    422 => ['Invalid input', [
                        'Missing fields' => ApiExamples::validation('title', ['The title field is required.', 'The description field is required.']),
                        'Wrong kind of category' => ApiExamples::validation('categoryId', ['This category does not fit this kind of ad.']),
                        'Budget range' => ApiExamples::validation('budgetMax', ['The highest budget cannot be lower than the lowest.']),
                    ]],
                ] + $rate + $unauthenticated,
            ],

            'PATCH /projects/{project}' => [
                'request' => [
                    'New title and budget' => ['title' => 'Landing page for two dental clinics', 'budgetMax' => 800],
                    'Move to another country' => ['countryId' => 2, 'cityId' => 4],
                ],
                'responses' => [
                    200 => ['The updated project', ['Updated' => ['data' => [...$project, 'title' => 'Landing page for two dental clinics']]]],
                    422 => ['Invalid input, or the project is no longer open', [
                        'Not editable' => ['message' => 'Only open projects can be edited.', 'code' => 'project_not_editable'],
                        'Type cannot change' => ApiExamples::validation('type', ['The type field is prohibited.']),
                    ]],
                ] + $forbidden + $notFound + $unauthenticated,
            ],

            'DELETE /projects/{project}' => [
                'responses' => [
                    200 => ['Deleted', ['Deleted' => ['success' => true]]],
                    422 => ['Has offers, or not open', [
                        'Has offers' => ['message' => 'This project already has offers. Close it instead of deleting it.', 'code' => 'project_has_proposals'],
                    ]],
                ] + $forbidden + $notFound + $unauthenticated,
            ],

            'POST /projects/{project}/close' => [
                'responses' => [
                    200 => ['Closed: pending offers are declined and their senders notified', ['Closed' => ['data' => [...$project, 'status' => 'closed']]]],
                    422 => ['The project is not open', [
                        'Not open' => ['message' => 'This project is no longer accepting offers.', 'code' => 'project_not_open'],
                    ]],
                ] + $forbidden + $notFound + $unauthenticated,
            ],

            'POST /projects/{project}/complete' => [
                'responses' => [
                    200 => ['Completed', ['Completed' => ['data' => [...$project, 'status' => 'completed', 'acceptedProposalId' => 10]]]],
                    422 => ['The project is not in progress', [
                        'Not in progress' => ['message' => 'Only a project in progress can be marked as completed.', 'code' => 'project_not_in_progress'],
                    ]],
                ] + $forbidden + $notFound + $unauthenticated,
            ],

            'GET /me/projects' => [
                'responses' => [
                    200 => ['"My Projects", newest first, each with its number of offers', [
                        'My projects' => HomepageExamples::paginated('/me/projects', [$mine, $inProgress], 2, 15),
                    ]],
                    422 => ['Invalid status', ['Unknown status' => ApiExamples::validation('status', ['The selected status is invalid.'])]],
                ] + $unauthenticated,
            ],

            'GET /projects/{project}/proposals' => [
                'responses' => [
                    200 => ['The offers on the owner\'s project', [
                        'Offers' => HomepageExamples::paginated('/projects/43/proposals', [
                            $offer, self::proposal(11, 43, 380, 9, 'pending', 'Mostafa Salem', 'Web Developer', 4.9, 53),
                        ], 2, 15),
                    ]],
                ] + $forbidden + $notFound + $unauthenticated,
            ],

            'POST /projects/{project}/proposals' => [
                'request' => [
                    'Offer' => ['amount' => 420, 'deliveryDays' => 6, 'message' => 'I build fast bilingual landing pages. Can start tomorrow.'],
                    'Offer with cents' => ['amount' => 399.5, 'deliveryDays' => 14, 'message' => 'Includes two rounds of revisions and the source files.'],
                ],
                'responses' => [
                    201 => ['Offer sent: the owner is notified', ['Offer' => ['data' => $offer]]],
                    422 => ['Invalid input, or a rule refused it. Refusals carry a `code` to react to', [
                        'No profile yet' => ['message' => 'Add your profile before sending offers.', 'code' => 'profile_required'],
                        'Wrong profile kind' => ['message' => 'Only freelancer profiles can send offers on projects.', 'code' => 'profile_type_mismatch'],
                        'Own project' => ['message' => 'You cannot send an offer on your own project.', 'code' => 'own_project'],
                        'Already offered' => ['message' => 'You already sent an offer on this project. Edit it instead.', 'code' => 'already_offered'],
                        'Project closed' => ['message' => 'This project is no longer accepting offers.', 'code' => 'project_not_open'],
                        'Invalid amount' => ApiExamples::validation('amount', ['The amount field must be at least 1.']),
                    ]],
                ] + $rate + $notFound + $unauthenticated,
            ],

            'GET /me/proposals' => [
                'responses' => [
                    200 => ['"My Offers", newest first, each with its project', [
                        'My offers' => HomepageExamples::paginated('/me/proposals', [[...$offer, 'project' => $project]], 1, 15),
                    ]],
                    422 => ['Invalid status', ['Unknown status' => ApiExamples::validation('status', ['The selected status is invalid.'])]],
                ] + $unauthenticated,
            ],

            'GET /proposals/{proposal}' => [
                'responses' => [
                    200 => ['The offer', ['Offer' => ['data' => [...$offer, 'project' => $project]]]],
                ] + $forbidden + $notFound + $unauthenticated,
            ],

            'PATCH /proposals/{proposal}' => [
                'request' => [
                    'Lower the price' => ['amount' => 380],
                    'Change everything' => ['amount' => 400, 'deliveryDays' => 5, 'message' => 'Updated plan: I can finish a day earlier.'],
                ],
                'responses' => [
                    200 => ['The updated offer', ['Updated' => ['data' => [...$offer, 'amount' => 380.0]]]],
                ] + $notPending + $forbidden + $notFound + $unauthenticated,
            ],

            'POST /proposals/{proposal}/withdraw' => [
                'responses' => [
                    200 => ['Withdrawn', ['Withdrawn' => ['data' => [...$offer, 'status' => 'withdrawn']]]],
                ] + $notPending + $forbidden + $notFound + $unauthenticated,
            ],

            'POST /proposals/{proposal}/accept' => [
                'responses' => [
                    200 => ['Accepted: the project goes in progress, every other pending offer is declined, and senders are notified', [
                        'Accepted' => ['data' => $accepted],
                    ]],
                    422 => ['Already answered, or the project is not open', [
                        'Already answered' => ['message' => 'This offer was already answered or withdrawn.', 'code' => 'proposal_not_pending'],
                        'Project not open' => ['message' => 'This project is no longer accepting offers.', 'code' => 'project_not_open'],
                    ]],
                ] + $forbidden + $notFound + $unauthenticated,
            ],

            'POST /proposals/{proposal}/decline' => [
                'responses' => [
                    200 => ['Declined: the sender is notified', ['Declined' => ['data' => [...$offer, 'status' => 'declined']]]],
                ] + $notPending + $forbidden + $notFound + $unauthenticated,
            ],

            'GET /me/profile' => [
                'responses' => [
                    200 => ['The profile', ['Profile' => ['data' => self::profile()]]],
                    404 => ['No profile yet: show the "Add Your Profile" card', ['No profile' => ['message' => 'No query results for model [App\\Models\\Profile]']]],
                ] + $unauthenticated,
            ],

            'PUT /me/profile' => [
                'request' => [
                    'Freelancer' => ['type' => 'freelancer', 'jobTitle' => 'Web Developer', 'bio' => 'Ten years of Laravel and Vue.', 'countryId' => 1, 'cityId' => 1],
                    'Influencer' => ['type' => 'influencer', 'jobTitle' => 'Lifestyle creator', 'bio' => 'Travel and food content.'],
                ],
                'responses' => [
                    201 => ['Profile created', ['Created' => ['data' => self::profile()]]],
                    200 => ['Profile updated', ['Updated' => ['data' => [...self::profile(), 'jobTitle' => 'Backend Developer']]]],
                    422 => ['Invalid input', [
                        'Missing job title' => ApiExamples::validation('jobTitle', ['The job title field is required.']),
                        'City from another country' => ApiExamples::validation('cityId', ['The city does not belong to the selected country.']),
                    ]],
                ] + $rate + $unauthenticated,
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function proposal(
        int $id, int $projectId, float|int $amount, int $days, string $status,
        string $name = 'Sara Mahmoud', string $job = 'Data Analyst', float $rating = 4.8, int $reviews = 79,
    ): array {
        return [
            'id' => $id,
            'projectId' => $projectId,
            'amount' => (float) $amount,
            'currency' => 'USD',
            'deliveryDays' => $days,
            'message' => 'I build fast bilingual landing pages. Can start tomorrow.',
            'status' => $status,
            'freelancer' => [
                'id' => 11, 'fullName' => $name, 'username' => strtolower(explode(' ', $name)[0]), 'avatarUrl' => null,
                'isOnline' => true, 'jobTitle' => $job, 'rating' => $rating, 'reviewsCount' => $reviews,
            ],
            'createdAt' => '2026-10-02T19:13:27.000000Z',
            'updatedAt' => '2026-10-02T19:13:27.000000Z',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private static function profile(): array
    {
        return [
            'id' => 11, 'type' => 'freelancer', 'jobTitle' => 'Web Developer', 'bio' => 'Ten years of Laravel and Vue.',
            'rating' => 0, 'reviewsCount' => 0, 'countryId' => 1, 'cityId' => 1, 'country' => 'Egypt', 'city' => 'Cairo',
            'isFeatured' => false, 'createdAt' => '2026-10-02T19:13:27.000000Z',
        ];
    }
}
