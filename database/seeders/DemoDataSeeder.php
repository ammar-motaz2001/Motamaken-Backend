<?php

namespace Database\Seeders;

use App\Models\Category;
use App\Models\Country;
use App\Models\Profile;
use App\Models\Project;
use App\Models\User;
use App\Services\Chat\ConversationService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Sample people, ads and chats so the homepage and Messages page have something to show.
 * Log in as demo@motamakin.com / Secret#123. Never runs in production.
 */
class DemoDataSeeder extends Seeder
{
    private const PASSWORD = 'Secret#123';

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string, 4: float}> first, last, username, job title, rating */
    private const FREELANCERS = [
        ['Hosaam', 'Amar', 'hosaam', 'Designer', 5.0],
        ['Mostafa', 'Salem', 'mostafa', 'Web Developer', 4.9],
        ['Omar', 'Khaled', 'omar', 'Social media Specialist', 4.8],
        ['Sara', 'Mahmoud', 'sara', 'Data Analyst', 4.8],
        ['Layla', 'Hassan', 'layla', 'Content Writer', 4.7],
        ['Youssef', 'Nabil', 'youssef', 'Mobile Developer', 4.6],
        ['Nour', 'Adel', 'nour', 'Translator', 4.2],
    ];

    /** @var array<int, array{0: string, 1: string, 2: string, 3: string}> first, last, username, bio */
    private const INFLUENCERS = [
        ['Dana', 'Fahad', 'dana', 'Lifestyle and travel creator'],
        ['Karim', 'Said', 'karim', 'Tech reviews and unboxing'],
        ['Hind', 'Yousef', 'hind', 'Beauty and fashion'],
    ];

    private const TITLES = [
        'Interactive Dashboard Design - Excel',
        'Logo design for a decoration company',
        'Online store for handmade furniture',
        'Arabic copywriting for a product launch',
        'Mobile app for appointment booking',
        'Data entry for 2,000 product listings',
        'Structural drawings for a small villa',
        'Promotional video for a new cafe',
    ];

    public function run(): void
    {
        if (app()->isProduction() || User::where('email', 'demo@motamakin.com')->exists()) {
            return;
        }

        $egypt = Country::firstWhere('code', 'EG');
        $saudi = Country::firstWhere('code', 'SA');
        $cities = fn (?Country $country) => $country?->cities()->pluck('id')->all() ?? [];

        $demo = $this->user('Abdulrahman', 'Al-Ghamdi', 'Abdulrahman', 'demo@motamakin.com', ['points' => 1908, 'kyc_status' => 'approved']);

        $freelancers = collect(self::FREELANCERS)->map(function ($row, $index) use ($egypt, $cities) {
            [$first, $last, $username, $title, $rating] = $row;
            $user = $this->user($first, $last, $username, "{$username}@motamakin.com");

            Profile::create([
                'user_id' => $user->id,
                'type' => Profile::TYPE_FREELANCER,
                'job_title' => $title,
                'bio' => "{$title} with several years of experience.",
                'rating' => $rating,
                'reviews_count' => 40 + $index * 13,
                'country_id' => $egypt?->id,
                'city_id' => fake()->randomElement($cities($egypt)),
                'is_featured' => $index < 6,
            ]);

            return $user;
        });

        $influencers = collect(self::INFLUENCERS)->map(function ($row, $index) use ($saudi, $cities) {
            [$first, $last, $username, $bio] = $row;
            $user = $this->user($first, $last, $username, "{$username}@motamakin.com");

            Profile::create([
                'user_id' => $user->id,
                'type' => Profile::TYPE_INFLUENCER,
                'job_title' => 'Influencer',
                'bio' => $bio,
                'rating' => 4.5 + $index / 10,
                'reviews_count' => 20 + $index * 7,
                'country_id' => $saudi?->id,
                'city_id' => fake()->randomElement($cities($saudi)),
                'is_featured' => false,
            ]);

            return $user;
        });

        $this->projects($freelancers->push($demo)->all(), $egypt, $cities($egypt));
        $this->influencerRequests($influencers->push($demo)->all(), $saudi, $cities($saudi));
        $this->conversations($demo, $freelancers->take(4)->all());

        Cache::flush();
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function user(string $first, string $last, string $username, string $email, array $extra = []): User
    {
        $user = User::create([
            'first_name' => $first,
            'last_name' => $last,
            'username' => $username,
            'email' => $email,
            'password' => self::PASSWORD,
            'subscribed' => true,
        ]);

        $user->forceFill(['email_verified_at' => now(), 'last_seen_at' => now(), ...$extra])->save();

        return $user;
    }

    /**
     * @param  array<int, User>  $owners
     * @param  array<int, int>  $cityIds
     */
    private function projects(array $owners, ?Country $country, array $cityIds): void
    {
        $categories = Category::ofType(Category::TYPE_PROJECT)->orderBy('sort_order')->get();

        foreach (range(0, 23) as $i) {
            $min = (1 + $i % 5) * 100;

            Project::create([
                'user_id' => $owners[$i % count($owners)]->id,
                'type' => Project::TYPE_PROJECT,
                'category_id' => $categories[$i % $categories->count()]->id,
                'title' => self::TITLES[$i % count(self::TITLES)],
                'description' => 'Logo design for a decoration and wood work company that needs a clean, modern identity for print and social media. '
                    .'Deliverables: three concepts, two revisions and the final files.',
                'budget_min' => $min,
                'budget_max' => $min + 200,
                'currency' => 'USD',
                'country_id' => $country?->id,
                'city_id' => $cityIds[$i % max(count($cityIds), 1)] ?? null,
                'is_featured' => $i < 8,
                'created_at' => now()->subHours($i * 3 + 1),
            ]);
        }
    }

    /**
     * @param  array<int, User>  $owners
     * @param  array<int, int>  $cityIds
     */
    private function influencerRequests(array $owners, ?Country $country, array $cityIds): void
    {
        $platforms = Category::ofType(Category::TYPE_INFLUENCER)->orderBy('sort_order')->get();

        foreach (range(0, 17) as $i) {
            Project::create([
                'user_id' => $owners[$i % count($owners)]->id,
                'type' => Project::TYPE_INFLUENCER_REQUEST,
                'category_id' => $platforms[$i % 4]->id,
                'title' => 'Promote a new product launch',
                'description' => 'Looking for an influencer to present our new product to their followers with one post and three stories.',
                'budget_min' => 100 + $i * 20,
                'budget_max' => 300 + $i * 20,
                'currency' => 'USD',
                'country_id' => $country?->id,
                'city_id' => $cityIds[$i % max(count($cityIds), 1)] ?? null,
                'created_at' => now()->subHours($i * 2 + 2),
            ]);
        }
    }

    /**
     * @param  array<int, User>  $people
     */
    private function conversations(User $demo, array $people): void
    {
        $chat = app(ConversationService::class);

        foreach ($people as $index => $person) {
            [$conversation] = $chat->findOrCreate($demo, $person, Project::inRandomOrder()->value('id'));

            $script = [
                [$demo, 'Hello! I saw your profile and I have a project for you.'],
                [$person, "I'm doing well, thank you! How can I help you today?"],
                [$demo, 'I need a logo and brand identity for a new company.'],
                [$person, 'Sure, send me the details and your budget and I will start today.'],
            ];

            foreach ($script as $step => [$sender, $body]) {
                $message = $chat->send($conversation, $sender, ['type' => 'text', 'body' => $body]);
                $time = now()->subHours(($index + 1) * 6)->addMinutes($step * 4);
                $message->forceFill(['created_at' => $time, 'updated_at' => $time])->save();
            }

            $conversation->forceFill(['last_message_at' => now()->subHours(($index + 1) * 6)->addMinutes(12)])->save();

            // The first two chats are fully read; the other two have unread messages from the other person.
            if ($index < 2) {
                $chat->markRead($conversation, $demo);
                $chat->markRead($conversation, $person);
            } elseif ($index === 2) {
                $chat->markRead($conversation, $person);
            }
        }
    }
}
