<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Staff roles: what each admin may see and do. A null role on an admin means full access (the owner accounts).
        Schema::create('admin_roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 40)->unique();
            $table->string('name_en', 80);
            $table->string('name_ar', 80);
            $table->json('permissions');
            // Built-in roles cannot be deleted.
            $table->boolean('is_system')->default(false);
            $table->timestamps();
        });
        DB::table('admin_roles')->insert(collect([
            ['super_admin', 'Super admin', 'مدير عام', ['*']],
            ['finance', 'Finance', 'المالية', ['overview', 'users.view', 'users.wallet', 'finance.view', 'finance.manage', 'disputes', 'exports']],
            ['moderator', 'Moderator', 'مشرف محتوى', ['overview', 'users.view', 'users.manage', 'kyc', 'reports', 'marketplace', 'disputes', 'exports']],
            ['support', 'Support', 'الدعم', ['overview', 'users.view', 'support', 'notifications']],
            ['content', 'Content', 'المحتوى', ['overview', 'content', 'notifications']],
        ])->map(fn ($r) => [
            'slug' => $r[0], 'name_en' => $r[1], 'name_ar' => $r[2], 'permissions' => json_encode($r[3]),
            'is_system' => true, 'created_at' => now(), 'updated_at' => now(),
        ])->all());

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('admin_role_id')->nullable()->constrained('admin_roles')->nullOnDelete();
            // A suspension with an end date lifts itself; null with suspended_at set means until lifted (a ban).
            $table->timestamp('suspended_until')->nullable();
            // Where the account was created from: used to spot self-referrals.
            $table->string('registration_ip', 45)->nullable();
        });

        // Every change an admin makes, with what was sent and, for settings, the values before.
        Schema::create('admin_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 80);
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->json('changes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['subject_type', 'subject_id']);
            $table->index(['admin_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });

        // Private notes the staff keep on a user.
        Schema::create('admin_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('author_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('body');
            $table->timestamps();
            $table->index(['user_id', 'created_at']);
        });

        // Blocked emails, phones, email domains, IP addresses and words.
        Schema::create('blacklist_entries', function (Blueprint $table) {
            $table->id();
            // email | phone | domain | ip | keyword
            $table->string('type', 20);
            $table->string('value');
            $table->string('reason');
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
            $table->unique(['type', 'value']);
        });

        // The history of an identity check: submitted, approved, rejected, asked for an update.
        Schema::create('kyc_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('kyc_submission_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->text('note')->nullable();
            $table->foreignId('actor_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'id']);
        });

        Schema::table('projects', function (Blueprint $table) {
            // Hidden by a moderator: off the site for everyone but the owner and the admins, until reactivated.
            $table->timestamp('hidden_at')->nullable();
            $table->string('hidden_reason')->nullable();
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->foreignId('parent_id')->nullable()->constrained('categories')->nullOnDelete();
            // Archived categories keep their projects but cannot be picked for new ones.
            $table->boolean('is_active')->default(true);
        });

        // The skills suggested in profiles and used as search filters.
        Schema::create('skills', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            $table->string('name_en', 80);
            $table->string('name_ar', 80);
            $table->foreignId('category_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::table('countries', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });
        Schema::table('cities', function (Blueprint $table) {
            $table->boolean('is_active')->default(true);
        });

        Schema::table('reviews', function (Blueprint $table) {
            // Hidden reviews leave the profile and the average.
            $table->timestamp('hidden_at')->nullable();
            $table->string('hidden_reason')->nullable();
            $table->foreignId('hidden_by')->nullable()->constrained('users')->nullOnDelete();
        });

        Schema::table('reports', function (Blueprint $table) {
            // Automatic screening files reports with no reporter.
            $table->foreignId('reporter_id')->nullable()->change();
            // user | ai
            $table->string('source', 10)->default('user');
            // low | normal | high
            $table->string('priority', 10)->default('normal');
            // What was done about it: hidden, suspended, closed, none...
            $table->string('action_taken', 40)->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->index(['priority', 'status']);
        });

        Schema::table('messages', function (Blueprint $table) {
            // Cleared by a moderator after a report: the bubble shows "removed".
            $table->timestamp('removed_at')->nullable();
        });

        // A second look at a settled dispute, asked by one of the two sides.
        Schema::create('dispute_appeals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('reason');
            // pending | upheld (first decision stands) | overturned (changed, with a money correction)
            $table->string('status', 20)->default('pending');
            $table->text('decision_note')->nullable();
            // Moved between the two sides' wallets when overturned.
            $table->bigInteger('adjustment_cents')->nullable();
            // client | freelancer: who receives the correction.
            $table->string('adjustment_to', 20)->nullable();
            $table->foreignId('decided_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
            $table->unique(['dispute_id', 'user_id']);
            $table->index(['status', 'created_at']);
        });

        // Medals, editable by admins (they used to live in config/engagement.php).
        Schema::create('medals', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('name_en', 80);
            $table->string('name_ar', 80);
            $table->string('description_en')->nullable();
            $table->string('description_ar')->nullable();
            $table->string('icon', 40)->nullable();
            // Earned automatically when this number reaches the target; null = only given by an admin.
            $table->string('metric', 40)->nullable();
            $table->unsignedInteger('target')->default(1);
            // Inactive medals are no longer awarded or shown, but holders keep them.
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
        });
        DB::table('medals')->insert(collect([
            ['verified', 'Verified', 'موثّق', 'identity_verified', 1, 'badge-check'],
            ['payment_verified', 'Payment verified', 'دفع موثّق', 'payment_methods', 1, 'credit-card'],
            ['first_job', 'First job', 'أول عمل', 'completed_jobs', 1, 'rocket'],
            ['rising_star', 'Rising star', 'نجم صاعد', 'completed_jobs', 10, 'star'],
            ['top_rated', 'Top rated', 'الأعلى تقييمًا', 'five_star_reviews', 5, 'award'],
            ['trusted_client', 'Trusted client', 'عميل موثوق', 'completed_projects', 5, 'shield-check'],
            ['distinguished', 'Distinguished', 'متميّز', 'promotions', 1, 'sparkles'],
            ['connector', 'Connector', 'صانع العلاقات', 'referrals', 5, 'users'],
        ])->map(fn ($m, $i) => [
            'code' => $m[0], 'name_en' => $m[1], 'name_ar' => $m[2], 'metric' => $m[3], 'target' => $m[4], 'icon' => $m[5],
            'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
        ])->all());

        Schema::table('user_medals', function (Blueprint $table) {
            // Set when an admin gave it by hand.
            $table->foreignId('awarded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('reason')->nullable();
        });

        Schema::table('point_transactions', function (Blueprint $table) {
            // Set for manual grants and deductions.
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // Announcements sent by the staff to everyone, one account type, or one user.
        Schema::create('broadcasts', function (Blueprint $table) {
            $table->id();
            $table->string('title_en');
            $table->string('title_ar');
            $table->text('body_en');
            $table->text('body_ar');
            // all | clients | freelancers | influencers | user
            $table->string('audience', 20);
            $table->foreignId('target_user_id')->nullable()->constrained('users')->nullOnDelete();
            // ["in_app"] or ["in_app", "email"]
            $table->json('channels');
            $table->unsignedInteger('recipients')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Admin wording for a notification type, replacing the default text in the bell and the email.
        Schema::create('notification_templates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 80)->unique();
            $table->text('text_en');
            $table->text('text_ar');
            $table->string('subject_en')->nullable();
            $table->string('subject_ar')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        // Every email sent or failed (addresses and subjects only, never the body).
        Schema::create('mail_logs', function (Blueprint $table) {
            $table->id();
            $table->string('to');
            $table->string('subject')->nullable();
            $table->string('notification', 80)->nullable();
            // sent | failed
            $table->string('status', 10);
            $table->text('error')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['status', 'created_at']);
        });

        // Problems talking to outside services (payments, email, captcha, AI).
        Schema::create('integration_logs', function (Blueprint $table) {
            $table->id();
            $table->string('service', 30);
            // error | warning | info
            $table->string('level', 10)->default('error');
            $table->string('message', 500);
            $table->json('context')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['service', 'created_at']);
        });

        // The FAQ, in groups, per audience.
        Schema::create('faq_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name_en', 100);
            $table->string('name_ar', 100);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
        Schema::create('faqs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('faq_category_id')->constrained()->cascadeOnDelete();
            $table->string('question_en');
            $table->string('question_ar');
            $table->text('answer_en');
            $table->text('answer_ar');
            // all | client | freelancer | influencer
            $table->string('audience', 20)->default('all');
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index(['faq_category_id', 'sort_order']);
        });

        Schema::table('contact_messages', function (Blueprint $table) {
            // The answer emailed from the admin panel.
            $table->text('reply')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('archived_at')->nullable();
        });

        // Support conversations between a user and the staff.
        Schema::create('support_tickets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('subject', 150);
            // account | payments | projects | technical | other
            $table->string('category', 30);
            // open (waiting for staff) | answered (waiting for the user) | closed
            $table->string('status', 20)->default('open');
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('last_message_at')->nullable();
            $table->timestamps();
            $table->index(['status', 'last_message_at']);
            $table->index(['user_id', 'last_message_at']);
        });
        Schema::create('support_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_ticket_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_staff')->default(false);
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->timestamps();
            $table->index(['support_ticket_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_messages');
        Schema::dropIfExists('support_tickets');
        Schema::table('contact_messages', fn (Blueprint $table) => $table->dropColumn(['reply', 'replied_at', 'archived_at']));
        Schema::dropIfExists('faqs');
        Schema::dropIfExists('faq_categories');
        Schema::dropIfExists('integration_logs');
        Schema::dropIfExists('mail_logs');
        Schema::dropIfExists('notification_templates');
        Schema::dropIfExists('broadcasts');
        Schema::table('point_transactions', fn (Blueprint $table) => $table->dropConstrainedForeignId('created_by'));
        Schema::table('user_medals', function (Blueprint $table) {
            $table->dropConstrainedForeignId('awarded_by');
            $table->dropColumn('reason');
        });
        Schema::dropIfExists('medals');
        Schema::dropIfExists('dispute_appeals');
        Schema::table('messages', fn (Blueprint $table) => $table->dropColumn('removed_at'));
        Schema::table('reports', function (Blueprint $table) {
            $table->dropIndex(['priority', 'status']);
            $table->dropConstrainedForeignId('handled_by');
            $table->dropColumn(['source', 'priority', 'action_taken']);
        });
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropConstrainedForeignId('hidden_by');
            $table->dropColumn(['hidden_at', 'hidden_reason']);
        });
        Schema::table('cities', fn (Blueprint $table) => $table->dropColumn('is_active'));
        Schema::table('countries', fn (Blueprint $table) => $table->dropColumn('is_active'));
        Schema::dropIfExists('skills');
        Schema::table('categories', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_id');
            $table->dropColumn('is_active');
        });
        Schema::table('projects', fn (Blueprint $table) => $table->dropColumn(['hidden_at', 'hidden_reason']));
        Schema::dropIfExists('kyc_events');
        Schema::dropIfExists('blacklist_entries');
        Schema::dropIfExists('admin_notes');
        Schema::dropIfExists('admin_logs');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('admin_role_id');
            $table->dropColumn(['suspended_until', 'registration_ip']);
        });
        Schema::dropIfExists('admin_roles');
    }
};
