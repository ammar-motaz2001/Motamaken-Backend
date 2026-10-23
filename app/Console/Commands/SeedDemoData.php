<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\City;
use App\Models\Conversation;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fills the platform with a large, realistic Arabic marketplace: users (clients, freelancers, influencers) with
 * profiles, projects in every state, offers, accepted deals and the conversations that go with them.
 *
 * Everything it creates is tagged by the e-mail domain below, so `--purge` removes it all again. It writes straight to
 * the tables in chunks (fast, and no model events, so no e-mails, notifications or AI screening fire). Nobody can sign
 * in to these accounts: they share one random, discarded password. On purpose it adds no reviews or star ratings
 * (visitors would take them as genuine) and no money (it would corrupt the ledger and the financial reports).
 */
#[Signature('demo:seed {--users=5000 : Accounts to create} {--projects=2000 : Projects to post} {--purge : Delete everything a previous run created} {--force : Do not ask for confirmation}')]
class SeedDemoData extends Command
{
    /** Marks every account this command created (never deliverable: .invalid). */
    public const DOMAIN = 'seed.mutamakin.invalid';

    private const FIRST = ['أحمد', 'محمد', 'عبدالله', 'خالد', 'فهد', 'سعود', 'عمر', 'يوسف', 'سلطان', 'ناصر', 'فيصل', 'تركي', 'ماجد', 'عبدالرحمن', 'سامي', 'راشد', 'بدر', 'حمد', 'زياد', 'مازن', 'طارق', 'هشام', 'وليد', 'كريم', 'مصطفى', 'إبراهيم', 'علي', 'حسن', 'أنس', 'معاذ',
        'سارة', 'نورة', 'ريم', 'لمى', 'هيا', 'دانة', 'جود', 'منى', 'أمل', 'هند', 'شهد', 'رغد', 'لينا', 'ياسمين', 'مريم', 'فاطمة', 'عائشة', 'رنا', 'دينا', 'ملاك'];

    private const FIRST_LATIN = ['ahmed', 'mohammed', 'abdullah', 'khalid', 'fahad', 'saud', 'omar', 'yousef', 'sultan', 'nasser', 'faisal', 'turki', 'majed', 'abdulrahman', 'sami', 'rashed', 'badr', 'hamad', 'ziyad', 'mazen', 'tariq', 'hesham', 'waleed', 'kareem', 'mostafa', 'ibrahim', 'ali', 'hassan', 'anas', 'moaz',
        'sara', 'noura', 'reem', 'lama', 'haya', 'dana', 'joud', 'mona', 'amal', 'hind', 'shahad', 'raghad', 'lina', 'yasmin', 'maryam', 'fatima', 'aisha', 'rana', 'dina', 'malak'];

    private const LAST = ['الحربي', 'القحطاني', 'الغامدي', 'الزهراني', 'العتيبي', 'الشهري', 'المطيري', 'الدوسري', 'العنزي', 'السبيعي', 'الشمري', 'المالكي', 'الأحمدي', 'السلمي', 'البلوي', 'الجهني', 'العمري', 'الرشيدي', 'الحسيني', 'المصري', 'النجار', 'الخطيب', 'الصالح', 'الكبيسي', 'المنصوري', 'الكعبي', 'البدري', 'السيد', 'عبدالعزيز', 'الفارس'];

    private const LAST_LATIN = ['alharbi', 'alqahtani', 'alghamdi', 'alzahrani', 'alotaibi', 'alshehri', 'almutairi', 'aldosari', 'alanazi', 'alsubaie', 'alshammari', 'almalki', 'alahmadi', 'alsulami', 'albalawi', 'aljohani', 'alomari', 'alrashidi', 'alhusseini', 'almasri', 'alnajjar', 'alkhatib', 'alsaleh', 'alkubaisi', 'almansouri', 'alkaabi', 'albadri', 'alsayed', 'abdulaziz', 'alfares'];

    /** Per project category slug: job titles, skills, and project titles. */
    private const FIELDS = [
        'website-it-software' => [
            'jobs' => ['مطوّر مواقع', 'مطوّر Laravel', 'مطوّر React', 'مهندس برمجيات', 'مطوّر ووردبريس', 'مطوّر متاجر إلكترونية'],
            'skills' => ['PHP', 'Laravel', 'React', 'Next.js', 'Node.js', 'WordPress', 'Shopify', 'MySQL', 'PostgreSQL', 'Tailwind CSS', 'API', 'SEO'],
            'titles' => ['تصميم وبرمجة موقع شركة', 'متجر إلكتروني متكامل', 'لوحة تحكم لإدارة الطلبات', 'تطوير موقع عقاري', 'ربط بوابة دفع بالموقع', 'تحسين سرعة موقع ووردبريس', 'نظام حجز مواعيد عبر الإنترنت', 'منصة تعليمية بسيطة'],
        ],
        'design-media-architecture' => [
            'jobs' => ['مصمم جرافيك', 'مصممة هوية بصرية', 'مصمم واجهات UI/UX', 'مونتير فيديو', 'مصمم موشن جرافيك', 'مصمم داخلي'],
            'skills' => ['Figma', 'Photoshop', 'Illustrator', 'After Effects', 'Premiere Pro', 'Branding', 'UI/UX', 'Motion Graphics', 'AutoCAD', '3ds Max'],
            'titles' => ['تصميم هوية بصرية لمطعم', 'تصميم شعار احترافي', 'مونتاج فيديو إعلاني قصير', 'تصميم واجهات تطبيق جوال', 'تصميم منشورات سوشيال ميديا لشهر', 'موشن جرافيك تعريفي بالشركة', 'تصميم داخلي لمكتب صغير', 'تصميم بروفايل للشركة'],
        ],
        'mobile-phone-computing' => [
            'jobs' => ['مطوّر تطبيقات Flutter', 'مطوّر iOS', 'مطوّر Android', 'مطوّر تطبيقات هجينة'],
            'skills' => ['Flutter', 'Dart', 'Swift', 'Kotlin', 'React Native', 'Firebase', 'REST API', 'App Store', 'Google Play'],
            'titles' => ['تطبيق توصيل طلبات', 'تطبيق حجز مواعيد للعيادات', 'تطوير تطبيق متجر إلكتروني', 'إصلاح أخطاء تطبيق Flutter', 'تطبيق لإدارة المهام', 'نشر تطبيق على المتاجر'],
        ],
        'writing-content' => [
            'jobs' => ['كاتب محتوى', 'كاتبة محتوى تسويقي', 'مترجم إنجليزي-عربي', 'مدقق لغوي', 'كاتب سيناريو'],
            'skills' => ['كتابة المحتوى', 'التسويق بالمحتوى', 'الترجمة', 'التدقيق اللغوي', 'SEO', 'كتابة السيناريو', 'إدارة السوشيال ميديا'],
            'titles' => ['كتابة محتوى موقع شركة', 'ترجمة ملف تعريفي إلى الإنجليزية', 'كتابة ١٠ مقالات مدونة', 'كتابة سيناريو فيديو إعلاني', 'تدقيق لغوي لكتاب', 'خطة محتوى لحسابات السوشيال ميديا'],
        ],
        'data-entry-admin' => [
            'jobs' => ['مدخل بيانات', 'مساعد افتراضي', 'أخصائي Excel', 'خدمة عملاء عن بُعد'],
            'skills' => ['Excel', 'Google Sheets', 'إدخال البيانات', 'البحث على الإنترنت', 'خدمة العملاء', 'Power BI'],
            'titles' => ['إدخال بيانات منتجات للمتجر', 'تنظيم ملفات Excel للمبيعات', 'مساعد افتراضي لمدة شهر', 'جمع بيانات موردين', 'إعداد تقرير مبيعات شهري'],
        ],
        'engineering-science' => [
            'jobs' => ['مهندس مدني', 'مهندس معماري', 'مهندس كهرباء', 'محلل بيانات'],
            'skills' => ['AutoCAD', 'Revit', 'SAP2000', 'Python', 'تحليل البيانات', 'MATLAB', 'الرسم الهندسي'],
            'titles' => ['رسم مخططات فيلا سكنية', 'دراسة إنشائية لمبنى صغير', 'تحليل بيانات مبيعات', 'تصميم مخطط كهربائي', 'حساب كميات مشروع'],
        ],
    ];

    /** Influencer requests, by platform category slug. */
    private const INFLUENCER_TITLES = ['إعلان منتج على حسابك', 'تغطية افتتاح فرع جديد', 'مراجعة تطبيق جوال', 'حملة رمضانية لمتجر', 'فيديو قصير عن مطعم', 'ترويج لعرض خاص', 'سناب يوم كامل لعلامة تجارية'];

    private const OFFER_MESSAGES = [
        'مرحباً، اطلعت على تفاصيل المشروع وأستطيع تنفيذه باحترافية وفي الوقت المحدد. لدي خبرة سابقة في أعمال مشابهة ويسعدني مشاركتك نماذج منها.',
        'السلام عليكم، قرأت الوصف جيداً وفهمت المطلوب. سأبدأ بمسودة أولية خلال يومين ثم نعدل حسب ملاحظاتك حتى التسليم النهائي.',
        'أهلاً بك، أعمل في هذا المجال منذ عدة سنوات. أقترح تقسيم العمل إلى مراحل واضحة مع تسليم كل مرحلة للمراجعة قبل الانتقال للتالية.',
        'مرحباً، يمكنني تنفيذ المشروع كاملاً مع الدعم بعد التسليم. هل يمكن مناقشة بعض التفاصيل الإضافية قبل البدء؟',
    ];

    private const CHAT = [
        ['مرحباً، شكراً لقبول عرضي. متى يناسبك أن نبدأ؟', 'أهلاً بك، يمكننا البدء اليوم. أرسلت لك الملفات المطلوبة.', 'وصلتني، سأرسل المسودة الأولى خلال يومين.', 'ممتاز، بانتظارك.'],
        ['السلام عليكم، هل لديك أي ملاحظات على المسودة؟', 'وعليكم السلام، العمل رائع. فقط أريد تعديل الألوان قليلاً.', 'تم، أرسلت النسخة المعدلة.', 'ممتاز جداً، شكراً لك.'],
        ['تم الانتهاء من المرحلة الأولى، يمكنك مراجعتها.', 'راجعتها وهي ممتازة، نكمل المرحلة الثانية.', 'تمام، سأبدأ فيها الآن.'],
    ];

    /**
     * Create the demo marketplace, or delete it with --purge.
     */
    public function handle(): int
    {
        if ($this->option('purge')) {
            return $this->purge();
        }

        $users = max(10, (int) $this->option('users'));
        $projects = max(0, (int) $this->option('projects'));
        if (! $this->option('force') && ! $this->confirm("Create {$users} demo accounts and {$projects} projects in ".app()->environment().'?')) {
            return self::FAILURE;
        }

        $started = microtime(true);
        $cats = Category::where('is_active', true)->get(['id', 'type', 'slug']);
        // City ids per country id.
        $cities = City::get(['id', 'country_id'])->groupBy('country_id')->map(fn ($group) => $group->pluck('id')->all())->all();
        $countryIds = array_keys($cities);
        $saudi = (int) (DB::table('countries')->where('code', 'SA')->value('id') ?? $countryIds[0] ?? 0);

        // Accounts: 55% freelancers, 15% influencers, 30% clients.
        $password = Hash::make(Str::random(48));
        $roles = [];
        $batch = Str::lower(Str::random(4));
        $this->withProgressBar(array_chunk(range(1, $users), 500), function (array $numbers) use (&$roles, $password, $batch, $cities, $countryIds, $saudi) {
            $rows = [];
            foreach ($numbers as $n) {
                $i = array_rand(self::FIRST);
                $l = array_rand(self::LAST);
                $created = $this->pastDate(300);
                $rows[] = [
                    'first_name' => self::FIRST[$i], 'last_name' => self::LAST[$l],
                    'username' => self::FIRST_LATIN[$i].'.'.self::LAST_LATIN[$l].'.'.$this->letters($n).$batch,
                    'email' => "u{$n}.{$batch}@".self::DOMAIN,
                    'password' => $password, 'locale' => 'ar', 'currency' => mt_rand(1, 10) <= 6 ? 'SAR' : 'USD',
                    'email_verified_at' => $created, 'last_seen_at' => $this->pastDate(30), 'created_at' => $created, 'updated_at' => $created,
                ];
            }
            DB::table('users')->insert($rows);
            $ids = DB::table('users')->whereIn('email', array_column($rows, 'email'))->pluck('id')->all();

            $profiles = [];
            foreach ($ids as $id) {
                $roll = mt_rand(1, 100);
                $role = $roll <= 55 ? 'freelancer' : ($roll <= 70 ? 'influencer' : 'client');
                $roles[$role][] = $id;
                if ($role !== 'client') {
                    $profiles[] = $this->profileRow($id, $role, $cities, $countryIds, $saudi);
                }
            }
            DB::table('profiles')->insert($profiles);
        });
        $this->newLine();

        // Projects with offers, accepted deals and their conversations.
        $made = ['projects' => 0, 'offers' => 0, 'deals' => 0, 'messages' => 0];
        $clients = $roles['client'] ?? [];
        if ($projects > 0 && $clients) {
            $this->withProgressBar(array_chunk(range(1, $projects), 200), function (array $chunk) use ($clients, $roles, $cats, $cities, $countryIds, &$made) {
                DB::transaction(function () use ($chunk, $clients, $roles, $cats, $cities, $countryIds, &$made) {
                    foreach ($chunk as $ignored) {
                        $this->project($clients, $roles, $cats, $cities, $countryIds, $made);
                    }
                });
            });
            $this->newLine();
        }

        $this->info(sprintf('Done in %.0fs: %d accounts (%d freelancers, %d influencers, %d clients), %d projects, %d offers, %d accepted deals, %d messages.',
            microtime(true) - $started, $users, count($roles['freelancer'] ?? []), count($roles['influencer'] ?? []), count($clients),
            $made['projects'], $made['offers'], $made['deals'], $made['messages']));
        $this->line('Remove it all with: php artisan demo:seed --purge');

        return self::SUCCESS;
    }

    /**
     * One project with its offers; projects in progress or done also get the accepted deal and a conversation.
     *
     * @param  list<int>  $clients
     * @param  array<string, list<int>>  $roles
     * @param  Collection<int, Category>  $cats
     * @param  array<int, list<int>>  $cities
     * @param  list<int>  $countryIds
     * @param  array<string, int>  $made
     */
    private function project(array $clients, array $roles, $cats, $cities, array $countryIds, array &$made): void
    {
        $influencer = mt_rand(1, 100) <= 20 && ! empty($roles['influencer']);
        $category = $cats->where('type', $influencer ? 'influencer' : 'project')->random();
        $field = self::FIELDS[$category->slug] ?? self::FIELDS['website-it-software'];
        $title = $influencer ? self::INFLUENCER_TITLES[array_rand(self::INFLUENCER_TITLES)] : $field['titles'][array_rand($field['titles'])];

        $min = [100, 200, 300, 500, 750, 1000, 1500, 2500][mt_rand(0, 7)];
        $max = $min * [2, 2, 3, 4][mt_rand(0, 3)];
        $currency = mt_rand(1, 10) <= 6 ? 'SAR' : 'USD';
        $roll = mt_rand(1, 100);
        $status = $roll <= 45 ? Project::STATUS_OPEN : ($roll <= 70 ? Project::STATUS_IN_PROGRESS : ($roll <= 90 ? Project::STATUS_COMPLETED : Project::STATUS_CLOSED));
        $created = $this->pastDate($status === Project::STATUS_OPEN ? 25 : 200);
        $country = mt_rand(1, 10) <= 7 && $countryIds ? $countryIds[array_rand($countryIds)] : null;

        $projectId = DB::table('projects')->insertGetId([
            'user_id' => $clients[array_rand($clients)],
            'type' => $influencer ? Project::TYPE_INFLUENCER_REQUEST : Project::TYPE_PROJECT,
            'category_id' => $category->id, 'title' => $title,
            'description' => $this->description($title, $field),
            'budget_min' => $min, 'budget_max' => $max, 'currency' => $currency,
            'country_id' => $country, 'city_id' => $country && mt_rand(0, 1) ? $cities[$country][array_rand($cities[$country])] : null,
            'status' => $status, 'is_featured' => false, 'created_at' => $created, 'updated_at' => $created,
        ]);
        $made['projects']++;

        $pool = $roles[$influencer ? 'influencer' : 'freelancer'] ?? [];
        $offers = [];
        foreach ((array) array_rand(array_flip($pool), min(count($pool), mt_rand(2, 7))) as $freelancerId) {
            $at = Carbon::parse($created)->addHours(mt_rand(1, 96));
            $offers[] = [
                'project_id' => $projectId, 'user_id' => $freelancerId,
                'amount' => mt_rand($min, $max), 'currency' => $currency, 'delivery_days' => mt_rand(3, 45),
                'message' => self::OFFER_MESSAGES[array_rand(self::OFFER_MESSAGES)],
                'status' => $status === Project::STATUS_OPEN ? Proposal::STATUS_PENDING : Proposal::STATUS_DECLINED,
                'created_at' => $at, 'updated_at' => $at,
            ];
        }
        DB::table('proposals')->insert($offers);
        $made['offers'] += count($offers);

        if (! in_array($status, [Project::STATUS_IN_PROGRESS, Project::STATUS_COMPLETED], true)) {
            return;
        }

        // One offer wins; the client and the freelancer talk it through.
        $winner = DB::table('proposals')->where('project_id', $projectId)->inRandomOrder()->first();
        DB::table('proposals')->where('id', $winner->id)->update(['status' => Proposal::STATUS_ACCEPTED]);
        DB::table('projects')->where('id', $projectId)->update(['accepted_proposal_id' => $winner->id]);
        $made['deals']++;

        $clientId = DB::table('projects')->where('id', $projectId)->value('user_id');
        $pairKey = Conversation::pairKey($clientId, $winner->user_id);
        if (DB::table('conversations')->where('pair_key', $pairKey)->exists()) {
            return;
        }
        $at = Carbon::parse($winner->created_at)->addHours(mt_rand(2, 48));
        $conversationId = DB::table('conversations')->insertGetId(['pair_key' => $pairKey, 'project_id' => $projectId, 'type' => 'direct', 'created_at' => $at, 'updated_at' => $at]);
        DB::table('conversation_user')->insert([['conversation_id' => $conversationId, 'user_id' => $clientId], ['conversation_id' => $conversationId, 'user_id' => $winner->user_id]]);

        $lastId = null;
        foreach (self::CHAT[array_rand(self::CHAT)] as $k => $body) {
            $at = $at->copy()->addMinutes(mt_rand(5, 600));
            $lastId = DB::table('messages')->insertGetId([
                'conversation_id' => $conversationId, 'sender_id' => $k % 2 === 0 ? $winner->user_id : $clientId,
                'type' => 'text', 'body' => $body, 'created_at' => $at, 'updated_at' => $at,
            ]);
            $made['messages']++;
        }
        DB::table('conversations')->where('id', $conversationId)->update(['last_message_id' => $lastId, 'last_message_at' => $at]);
        DB::table('conversation_user')->where('conversation_id', $conversationId)->update(['last_read_message_id' => $lastId]);
    }

    /**
     * A freelancer or influencer profile row: job title, bio, skills, languages, location and (influencers) channels.
     *
     * @param  array<int, list<int>>  $cities
     * @param  list<int>  $countryIds
     * @return array<string, mixed>
     */
    private function profileRow(int $userId, string $role, $cities, array $countryIds, int $saudi): array
    {
        $country = mt_rand(1, 10) <= 5 && $saudi ? $saudi : ($countryIds ? $countryIds[array_rand($countryIds)] : null);
        $now = now();

        if ($role === 'influencer') {
            $platforms = (array) array_rand(array_flip(['instagram', 'tiktok', 'snapchat', 'youtube', 'x']), mt_rand(1, 3));
            $channels = array_map(fn (string $platform) => [
                'platform' => $platform, 'handle' => '@'.Str::lower(Str::random(mt_rand(6, 10))),
                'url' => null, 'followers' => (int) round(exp(mt_rand(800, 1350) / 100)), 'engagementRate' => mt_rand(10, 80) / 10,
            ], $platforms);
            $job = ['صانع محتوى', 'مؤثرة في مجال الجمال', 'مؤثر تقني', 'صانع محتوى طبخ', 'مؤثر رياضي', 'مدونة سفر'][mt_rand(0, 5)];
            $skills = ['صناعة المحتوى', 'التصوير', 'المونتاج', 'التسويق عبر المؤثرين', 'إدارة الحملات'];
        } else {
            $field = self::FIELDS[array_rand(self::FIELDS)];
            $job = $field['jobs'][array_rand($field['jobs'])];
            $skills = $field['skills'];
            $channels = null;
        }

        return [
            'user_id' => $userId, 'type' => $role === 'influencer' ? Profile::TYPE_INFLUENCER : Profile::TYPE_FREELANCER,
            'job_title' => $job,
            'bio' => "{$job} بخبرة ".mt_rand(2, 12).' سنوات. أحرص على الجودة والالتزام بالمواعيد والتواصل المستمر مع العميل حتى يخرج العمل بالشكل المطلوب.',
            'rating' => 0, 'reviews_count' => 0, 'is_featured' => false,
            'country_id' => $country, 'city_id' => $country && isset($cities[$country]) ? $cities[$country][array_rand($cities[$country])] : null,
            'skills' => json_encode((array) array_rand(array_flip($skills), min(count($skills), mt_rand(3, 6))), JSON_UNESCAPED_UNICODE),
            'languages' => json_encode([['name' => 'Arabic', 'level' => 'native'], ['name' => 'English', 'level' => ['basic', 'good', 'fluent'][mt_rand(0, 2)]]]),
            'channels' => $channels ? json_encode($channels, JSON_UNESCAPED_UNICODE) : null,
            'created_at' => $now, 'updated_at' => $now,
        ];
    }

    /**
     * A project description built from its title and the field's skills.
     *
     * @param  array{jobs: list<string>, skills: list<string>, titles: list<string>}  $field
     */
    private function description(string $title, array $field): string
    {
        $skills = implode('، ', (array) array_rand(array_flip($field['skills']), min(3, count($field['skills']))));

        return "نبحث عن شخص محترف لتنفيذ مشروع: {$title}.\n\n"
            ."المطلوب: فهم احتياجاتنا، تقديم تصور أولي، ثم التنفيذ على مراحل مع التعديلات اللازمة حتى التسليم النهائي.\n"
            ."يفضَّل من لديه خبرة في: {$skills}.\n\n"
            .'يرجى إرفاق نماذج من أعمال سابقة مشابهة وذكر المدة المتوقعة للتنفيذ.';
    }

    /**
     * Delete every account this command created; their profiles, projects, offers and messages go with them.
     */
    private function purge(): int
    {
        $users = DB::table('users')->where('email', 'like', '%@'.self::DOMAIN);
        $count = (clone $users)->count();
        if (! $count) {
            $this->info('No demo data to remove.');

            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm("Delete {$count} demo accounts and everything they created?")) {
            return self::FAILURE;
        }

        DB::transaction(function () use ($users) {
            $ids = (clone $users)->pluck('id');
            DB::table('conversations')->whereIn('id', DB::table('conversation_user')->whereIn('user_id', $ids)->select('conversation_id'))->delete();
            // Real users' offers on demo projects, and demo offers on real projects, go with their projects/authors.
            foreach ($ids->chunk(1000) as $chunk) {
                DB::table('users')->whereIn('id', $chunk)->delete();
            }
        });
        $this->info("Removed {$count} demo accounts and their profiles, projects, offers and conversations.");

        return self::SUCCESS;
    }

    /**
     * A random moment in the last $days days.
     */
    private function pastDate(int $days): string
    {
        return now()->subMinutes(mt_rand(0, $days * 24 * 60))->toDateTimeString();
    }

    /**
     * A number written with letters only (usernames allow letters and dots).
     */
    private function letters(int $n): string
    {
        $out = '';
        do {
            $out = chr(97 + $n % 26).$out;
            $n = intdiv($n, 26) - 1;
        } while ($n >= 0);

        return $out;
    }
}
