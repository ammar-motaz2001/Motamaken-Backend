<?php

namespace App\OpenApi;

/**
 * Swagger examples for the homepage, search bar and header endpoints. See ApiExamples for how they are applied.
 */
class HomepageExamples
{
    /**
     * @return array<string, array{request?: array, responses?: array<int, array{0: string, 1: array}|null>}>
     */
    public static function operations(): array
    {
        $web = self::category(1, 'website-it-software', 'Website, IT & Software', 'laptop');
        $design = self::category(2, 'design-media-architecture', 'Design, Media & Architecture', 'brush');
        $mobile = self::category(3, 'mobile-phone-computing', 'Mobile Phone & Computing', 'smartphone');
        $instagram = self::category(7, 'instagram', 'Instagram', 'instagram', 'influencer');
        $facebook = self::category(8, 'facebook', 'Facebook', 'facebook', 'influencer');
        $youtube = self::category(9, 'youtube', 'YouTube', 'youtube', 'influencer');

        $hosaam = self::profile(1, 8, 'Hosaam Amar', 'hosaam', 'Designer', 5.0, 40);
        $mostafa = self::profile(2, 9, 'Mostafa Salem', 'mostafa', 'Web Developer', 4.9, 53);
        $dashboard = self::project(1, 'Interactive Dashboard Design - Excel', $web, 8, 'Hosaam Amar', 100, 300);
        $logo = self::project(2, 'Logo design for a decoration company', $design, 9, 'Mostafa Salem', 200, 400);
        $store = self::project(3, 'Online store for handmade furniture', $mobile, 10, 'Omar Khaled', 300, 500);
        $promo = self::project(25, 'Promote a new product launch', $instagram, 15, 'Dana Fahad', 100, 300, 'influencer_request', 'Saudi Arabia', 'Riyadh');

        $notFound = [404 => ['Not found', ['Not found' => ['message' => 'No query results for model [App\\Models\\Project] 999']]]];

        return [
            'GET /home' => [
                'responses' => [
                    200 => ['Everything the homepage shows (the lists are shortened here: the real slider lists hold 6 cards)', [
                        'Homepage' => [
                            'featuredProfiles' => [$hosaam, $mostafa],
                            'distinctiveProjects' => [$dashboard, $logo],
                            'projectCategories' => [$web, $design, $mobile],
                            'recentProjects' => [$dashboard],
                            'influencerPlatforms' => [$instagram, $facebook, $youtube],
                            'latestInfluencerRequests' => [$promo],
                        ],
                    ]],
                ],
            ],

            'GET /projects' => [
                'responses' => [
                    200 => ['A page of open projects, newest first', [
                        'Projects of a category' => self::paginated('/projects?category=website-it-software', [$dashboard], 21, 15),
                        'Influencer requests on Instagram' => self::paginated('/projects?type=influencer_request&category=instagram', [$promo], 5, 15),
                    ]],
                    422 => ['Invalid filter', [
                        'Unknown category' => ApiExamples::validation('category', ['The selected category is invalid.']),
                    ]],
                ],
            ],

            'GET /projects/{project}' => [
                'responses' => [
                    200 => ['The project', ['Project' => ['data' => $dashboard]]],
                ] + $notFound,
            ],

            'GET /search' => [
                'responses' => [
                    200 => ['Grouped results for type=all; a single paginated list for any other type', [
                        'type=all' => [
                            'projects' => [$dashboard],
                            'influencerRequests' => [$promo],
                            'influencers' => [],
                            'freelancers' => [$hosaam],
                        ],
                        'type=freelancers' => self::paginated('/search?type=freelancers&q=design', [$hosaam], 1, 15),
                    ]],
                    422 => ['Invalid filter', [
                        'Unknown type' => ApiExamples::validation('type', ['The selected type is invalid.']),
                    ]],
                ],
            ],

            'GET /categories' => [
                'responses' => [
                    200 => ['Categories and/or platforms', [
                        'type=project' => ['data' => [$web, $design, $mobile]],
                        'type=influencer' => ['data' => [$instagram, $facebook, $youtube]],
                    ]],
                    422 => ['Invalid type', [
                        'Unknown type' => ApiExamples::validation('type', ['The selected type is invalid.']),
                    ]],
                ],
            ],

            'GET /countries' => [
                'responses' => [
                    200 => ['Countries sorted by name in the request language', [
                        'English' => ['data' => [['id' => 1, 'code' => 'EG', 'name' => 'Egypt'], ['id' => 2, 'code' => 'SA', 'name' => 'Saudi Arabia']]],
                        'Arabic (Accept-Language: ar)' => ['data' => [['id' => 1, 'code' => 'EG', 'name' => 'مصر'], ['id' => 2, 'code' => 'SA', 'name' => 'السعودية']]],
                    ]],
                ],
            ],

            'GET /countries/{country}/cities' => [
                'responses' => [
                    200 => ['Cities of the country', [
                        'Egypt' => ['data' => [
                            ['id' => 2, 'countryId' => 1, 'name' => 'Alexandria'],
                            ['id' => 1, 'countryId' => 1, 'name' => 'Cairo'],
                            ['id' => 3, 'countryId' => 1, 'name' => 'Giza'],
                        ]],
                    ]],
                    404 => ['Unknown country', ['Not found' => ['message' => 'No query results for model [App\\Models\\Country] 999']]],
                ],
            ],

            'GET /currencies' => [
                'responses' => [
                    200 => ['Currencies of the header dropdown', [
                        'Currencies' => [
                            ['code' => 'SAR', 'symbol' => 'SAR', 'name' => 'Saudi Riyal'],
                            ['code' => 'USD', 'symbol' => '$', 'name' => 'US Dollar'],
                            ['code' => 'AED', 'symbol' => 'AED', 'name' => 'UAE Dirham'],
                            ['code' => 'EUR', 'symbol' => '€', 'name' => 'Euro'],
                        ],
                    ]],
                ],
            ],

            'GET /languages' => [
                'responses' => [
                    200 => ['Languages of the header dropdown', [
                        'Languages' => [
                            ['code' => 'en', 'label' => 'ENG', 'name' => 'English'],
                            ['code' => 'ar', 'label' => 'ARA', 'name' => 'العربية'],
                            ['code' => 'fr', 'label' => 'FR', 'name' => 'Français'],
                        ],
                    ]],
                ],
            ],

            'GET /me/summary' => [
                'responses' => [
                    200 => ['The header data', [
                        'Summary' => [
                            'user' => ApiExamples::user([
                                'id' => 7, 'firstName' => 'Abdulrahman', 'lastName' => 'Al-Ghamdi', 'fullName' => 'Abdulrahman Al-Ghamdi',
                                'username' => 'Abdulrahman', 'email' => 'demo@motamakin.com', 'points' => 1908, 'locale' => 'ar',
                            ]),
                            'wallet' => ['balance' => 490.0, 'currency' => 'USD'],
                            'points' => 1908,
                            'unreadMessages' => 4,
                            'unreadNotifications' => 2,
                        ],
                    ]],
                ] + ApiExamples::unauthenticated(),
            ],

            'PATCH /me/preferences' => [
                'request' => [
                    'Arabic, Saudi riyal, dark mode' => ['locale' => 'ar', 'currency' => 'SAR', 'theme' => 'dark'],
                    'Only the theme' => ['theme' => 'light'],
                ],
                'responses' => [
                    200 => ['The updated user', [
                        'Updated' => ['data' => ApiExamples::user(['locale' => 'ar', 'currency' => 'SAR', 'theme' => 'dark'])],
                    ]],
                    422 => ['Unsupported value', [
                        'Unknown currency' => ApiExamples::validation('currency', ['The selected currency is invalid.']),
                    ]],
                ] + ApiExamples::unauthenticated(),
            ],

            'GET /notifications' => [
                'responses' => [
                    200 => ['The bell dropdown, newest first', [
                        'Notifications' => self::paginated('/notifications', [
                            self::notification('9d4c1b6e-6f1c-4a53-9a43-7e0c4d3a9b11', 'Hosaam Amar', 8, 1, null),
                            self::notification('3b7a2f90-1c58-4e1d-8d6a-0a5e2b7c4f22', 'Mostafa Salem', 9, 2, '2026-10-02T11:05:00.000000Z'),
                        ], 2, 20),
                    ]],
                ] + ApiExamples::unauthenticated(),
            ],

            'POST /notifications/{notification}/read' => [
                'responses' => [
                    200 => ['The notification, now read', [
                        'Read' => ['data' => self::notification('9d4c1b6e-6f1c-4a53-9a43-7e0c4d3a9b11', 'Hosaam Amar', 8, 1, '2026-10-02T16:40:00.000000Z')],
                    ]],
                    404 => ['Not yours, or unknown', ['Not found' => ['message' => 'No query results for model [Illuminate\\Notifications\\DatabaseNotification] x']]],
                ] + ApiExamples::unauthenticated(),
            ],

            'POST /notifications/read-all' => [
                'responses' => [
                    200 => ['All marked as read', ['Done' => ['success' => true]]],
                ] + ApiExamples::unauthenticated(),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function category(int $id, string $slug, string $name, string $icon, string $type = 'project'): array
    {
        return ['id' => $id, 'type' => $type, 'slug' => $slug, 'name' => $name, 'icon' => $icon];
    }

    /**
     * @return array<string, mixed>
     */
    public static function profile(int $id, int $userId, string $fullName, string $username, string $jobTitle, float $rating, int $reviews): array
    {
        return [
            'id' => $id,
            'userId' => $userId,
            'fullName' => $fullName,
            'username' => $username,
            'avatarUrl' => null,
            'type' => 'freelancer',
            'jobTitle' => $jobTitle,
            'rating' => $rating,
            'reviewsCount' => $reviews,
            'country' => 'Egypt',
            'city' => 'Cairo',
        ];
    }

    /**
     * @param  array<string, mixed>  $category
     * @return array<string, mixed>
     */
    public static function project(
        int $id, string $title, array $category, int $ownerId, string $owner, int $min, int $max,
        string $type = 'project', string $country = 'Egypt', string $city = 'Cairo', array $extra = [],
    ): array {
        $description = $type === 'project'
            ? 'Logo design for a decoration and wood work company that needs a clean, modern identity for print and social media. Deliverables: three concepts, two revisions and the final files.'
            : 'Looking for an influencer to present our new product to their followers with one post and three stories.';

        return [
            'id' => $id,
            'type' => $type,
            'title' => $title,
            'description' => $description,
            'excerpt' => mb_substr($description, 0, 97).'...',
            'budget' => ['min' => $min, 'max' => $max, 'currency' => 'USD'],
            'status' => 'open',
            'acceptedProposalId' => null,
            'category' => $category,
            'owner' => ['id' => $ownerId, 'fullName' => $owner, 'username' => strtolower(explode(' ', $owner)[0]), 'avatarUrl' => null, 'isOnline' => true],
            'country' => $country,
            'city' => $city,
            'createdAt' => '2026-10-02T15:31:37.000000Z',
            ...$extra,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function notification(string $id, string $name, int $userId, int $conversationId, ?string $readAt): array
    {
        return [
            'id' => $id,
            'type' => 'conversation.started',
            'message' => "{$name} started a conversation with you.",
            'user' => ['id' => $userId, 'fullName' => $name, 'avatarUrl' => null],
            'conversationId' => $conversationId,
            'projectId' => null,
            'proposalId' => null,
            'readAt' => $readAt,
            'createdAt' => '2026-10-02T10:35:37.000000Z',
        ];
    }

    /**
     * Laravel's page wrapper around a list of items.
     *
     * @param  list<array<string, mixed>>  $items
     * @return array<string, mixed>
     */
    public static function paginated(string $path, array $items, int $total, int $perPage): array
    {
        $base = 'http://localhost:8000/api'.strtok($path, '?');
        $last = max(1, (int) ceil($total / $perPage));
        $page = fn (int $n) => "{$base}?page={$n}";

        return [
            'data' => $items,
            'links' => ['first' => $page(1), 'last' => $page($last), 'prev' => null, 'next' => $last > 1 ? $page(2) : null],
            'meta' => [
                'current_page' => 1,
                'from' => $items ? 1 : null,
                'last_page' => $last,
                'links' => [
                    ['url' => null, 'label' => '&laquo; Previous', 'page' => null, 'active' => false],
                    ['url' => $page(1), 'label' => '1', 'page' => 1, 'active' => true],
                    ['url' => null, 'label' => 'Next &raquo;', 'page' => null, 'active' => false],
                ],
                'path' => $base,
                'per_page' => $perPage,
                'to' => count($items) ?: null,
                'total' => $total,
            ],
        ];
    }
}
