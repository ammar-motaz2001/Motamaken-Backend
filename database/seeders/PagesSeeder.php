<?php

namespace Database\Seeders;

use App\Models\Page;
use Illuminate\Database\Seeder;

/**
 * Starter text for the footer's information pages. Only adds pages that do not exist, so admins' edits are never
 * overwritten. The legal pages are drafts to be replaced with reviewed text.
 */
class PagesSeeder extends Seeder
{
    private const DRAFT_EN = '';

    private const DRAFT_AR = '';

    public function run(): void
    {
        foreach ($this->pages() as $order => [$slug, $section, $titleEn, $titleAr, $bodyEn, $bodyAr]) {
            Page::firstOrCreate(['slug' => $slug], [
                'section' => $section, 'title_en' => $titleEn, 'title_ar' => $titleAr,
                'body_en' => $bodyEn, 'body_ar' => $bodyAr, 'sort_order' => $order,
            ]);
        }
    }

    /**
     * @return list<array{0: string, 1: string, 2: string, 3: string, 4: string, 5: string}>
     */
    private function pages(): array
    {
        return [
            ['services', 'about', 'Our services', 'خدماتنا',
                "{{site_name}} connects people who need work done with skilled freelancers and social media influencers.\n\n"
                ."## For clients\n- **Post a project** for free and receive offers from freelancers.\n- **Post an influencer request** to promote your brand.\n"
                ."- **Pay safely**: money waits in escrow until you approve the work.\n\n"
                ."## For freelancers and influencers\n- Build a profile and portfolio.\n- Send offers on projects and requests.\n"
                ."- Work alone or as a **team** that shares the pay.\n- Get paid to your wallet and withdraw to your bank.\n\n"
                ."## Extras\n- **Premium plans** with more offers and lower fees.\n- **Promotion**: feature your profile or project on the homepage, or run a pay-per-visit campaign.",
                "تربط {{site_name}} أصحاب الأعمال بالمستقلين المهرة ومؤثري التواصل الاجتماعي.\n\n"
                ."## لأصحاب المشاريع\n- **انشر مشروعك** مجاناً واستقبل العروض من المستقلين.\n- **انشر طلباً للمؤثرين** للترويج لعلامتك التجارية.\n"
                ."- **ادفع بأمان**: تبقى الأموال في الضمان حتى تعتمد العمل.\n\n"
                ."## للمستقلين والمؤثرين\n- أنشئ ملفاً شخصياً ومعرض أعمال.\n- أرسل عروضك على المشاريع والطلبات.\n"
                ."- اعمل منفرداً أو ضمن **فريق** يتقاسم الأجر.\n- استلم أرباحك في محفظتك واسحبها إلى حسابك البنكي.\n\n"
                ."## إضافات\n- **باقات مميزة** بعروض أكثر ورسوم أقل.\n- **الترويج**: ميّز ملفك أو مشروعك في الصفحة الرئيسية، أو أطلق حملة بالدفع لكل زيارة."],

            ['guide', 'help', 'How it works', 'دليل الاستخدام',
                "## 1. Create your account\nSign up with your email or phone and confirm the code we send you.\n\n"
                ."## 2. Post or find work\nClients post a project with a budget. Freelancers browse projects and send an offer with a price, a delivery time and a message.\n\n"
                ."## 3. Hire and protect the payment\nThe client accepts an offer, splits the price into milestones and pays each milestone into escrow.\n\n"
                ."## 4. Deliver and get paid\nThe freelancer delivers each milestone. When the client approves it, the money goes to the freelancer's wallet, minus the platform fee of {{site_fee}}%.\n\n"
                ."## 5. Something went wrong?\nEither side can open a dispute on a milestone. The money stays frozen until it is settled.",
                "## 1. أنشئ حسابك\nسجّل ببريدك الإلكتروني أو رقم هاتفك وأكّد الرمز الذي نرسله إليك.\n\n"
                ."## 2. انشر عملاً أو ابحث عنه\nينشر صاحب المشروع مشروعه مع الميزانية، ويتصفح المستقلون المشاريع ويرسلون عروضهم بالسعر ومدة التسليم ورسالة.\n\n"
                ."## 3. وظّف واحمِ الدفعة\nيقبل صاحب المشروع عرضاً، ويقسّم السعر إلى مراحل، ويدفع كل مرحلة إلى الضمان.\n\n"
                ."## 4. سلّم واستلم أجرك\nيسلّم المستقل كل مرحلة، وعند اعتمادها تنتقل الأموال إلى محفظته بعد خصم رسوم المنصة {{site_fee}}%.\n\n"
                ."## 5. حدثت مشكلة؟\nيمكن لأي طرف فتح نزاع على مرحلة، وتبقى الأموال مجمّدة حتى تتم التسوية."],

            ['bidding-system', 'help', 'Bidding system', 'نظام المزايدة',
                "- Freelancers send **one offer per project**: a price, a delivery time in days and a cover letter.\n"
                ."- An offer can be edited or withdrawn until the client answers it.\n"
                ."- A free account can send {{free_offers}} offers a month; premium plans send more.\n"
                .'- When the client accepts an offer, the project starts and the other offers are declined automatically. Everyone is notified.',
                "- يرسل المستقل **عرضاً واحداً لكل مشروع**: السعر ومدة التسليم بالأيام ورسالة تعريفية.\n"
                ."- يمكن تعديل العرض أو سحبه حتى يرد عليه صاحب المشروع.\n"
                ."- يمكن للحساب المجاني إرسال {{free_offers}} عرضاً شهرياً، والباقات المميزة ترسل أكثر.\n"
                .'- عند قبول عرض يبدأ المشروع وتُرفض العروض الأخرى تلقائياً، ويتم إشعار الجميع.'],

            ['evaluation-system', 'help', 'Evaluation system', 'نظام التقييم',
                "When a project is finished, the client and the freelancer can rate each other from 1 to 5 stars and leave a comment.\n\n"
                ."A profile's rating is the average of the reviews it received. Reviews are public and help others choose who to work with. Reviews that break the rules can be reported.",
                "عند انتهاء المشروع، يمكن لصاحب المشروع والمستقل تقييم بعضهما من 1 إلى 5 نجوم وكتابة تعليق.\n\n"
                .'تقييم الملف الشخصي هو متوسط التقييمات التي حصل عليها. التقييمات علنية وتساعد الآخرين على اختيار من يعملون معه، ويمكن الإبلاغ عن التقييمات المخالفة.'],

            ['payment-protection', 'help', 'Payment protection', 'حماية الدفع',
                "Every hired project can be paid through **escrow**:\n\n"
                ."1. The client splits the agreed price into milestones.\n2. The client pays a milestone into escrow. The freelancer can see the money is there and starts working.\n"
                ."3. The freelancer delivers. The client approves the work or asks for changes.\n4. On approval the money goes to the freelancer, minus the platform fee of {{site_fee}}%.\n\n"
                .'The client cannot take the money back alone, and the freelancer cannot take it before approval. Disagreements are settled through a dispute.',
                "يمكن دفع كل مشروع عبر **الضمان**:\n\n"
                ."1. يقسّم صاحب المشروع السعر المتفق عليه إلى مراحل.\n2. يدفع مرحلة إلى الضمان، فيرى المستقل أن المال موجود ويبدأ العمل.\n"
                ."3. يسلّم المستقل العمل، ويعتمده صاحب المشروع أو يطلب تعديلات.\n4. عند الاعتماد ينتقل المال إلى المستقل بعد خصم رسوم المنصة {{site_fee}}%.\n\n"
                .'لا يستطيع صاحب المشروع استرداد المال وحده، ولا يستطيع المستقل أخذه قبل الاعتماد، ويُحسم الخلاف عبر نزاع.'],

            ['disputes-system', 'help', 'Disputes system', 'نظام النزاعات',
                "If something goes wrong with a milestone that is paid or delivered, either side can **open a dispute**.\n\n"
                ."- The milestone is frozen: nobody can pay it out or change it.\n- Both sides explain and upload evidence on the case page.\n"
                ."- The other side can agree at any time, which settles the case at once.\n"
                .'- Otherwise an admin reviews the case and decides: refund the client, pay the freelancer, or split the amount.',
                "إذا حدثت مشكلة في مرحلة مدفوعة أو مسلّمة، يمكن لأي طرف **فتح نزاع**.\n\n"
                ."- تُجمَّد المرحلة: لا يمكن صرفها أو تغييرها.\n- يشرح الطرفان ويرفعان الأدلة في صفحة القضية.\n"
                ."- يمكن للطرف الآخر الموافقة في أي وقت فتُحسم القضية فوراً.\n"
                .'- وإلا يراجع أحد المشرفين القضية ويقرر: إعادة المبلغ لصاحب المشروع، أو صرفه للمستقل، أو تقسيمه.'],

            ['guarantee-of-rights', 'help', 'Guarantee of rights', 'ضمان الحقوق',
                "We protect both sides of every deal:\n\n- **Clients** only pay for approved work, through escrow.\n"
                ."- **Freelancers** see the money in escrow before they start, so they know they will be paid.\n"
                ."- Every movement of money is recorded and can be traced.\n- Disputes are reviewed by our team with the evidence from both sides.",
                "نحمي طرفي كل اتفاق:\n\n- **أصحاب المشاريع** يدفعون فقط مقابل العمل المعتمد عبر الضمان.\n"
                ."- **المستقلون** يرون المال في الضمان قبل البدء، فيضمنون أجرهم.\n"
                ."- كل حركة مالية مسجّلة ويمكن تتبعها.\n- يراجع فريقنا النزاعات بالأدلة المقدّمة من الطرفين."],

            ['black-list', 'help', 'Black list', 'القائمة السوداء',
                "Accounts that break our rules (fraud, fake reviews, taking payments outside the platform, abuse) can be suspended.\n\n"
                .'A suspended account cannot use the platform. If you meet someone who asks you to pay or work outside {{site_name}}, report them from their profile.',
                "قد يتم تعليق الحسابات التي تخالف قواعدنا (الاحتيال، التقييمات المزيفة، تلقي المدفوعات خارج المنصة، الإساءة).\n\n"
                .'لا يمكن للحساب المعلّق استخدام المنصة. إذا طلب منك أحد الدفع أو العمل خارج {{site_name}}، أبلغ عنه من ملفه الشخصي.'],

            ['site-fees', 'help', 'Site fees', 'رسوم الموقع',
                "| What | Fee |\n|---|---|\n| Posting a project or an influencer request | Free |\n| Sending offers | Free ({{free_offers}} a month on the free plan) |\n"
                ."| Payments you receive for work | {{site_fee}}% (lower on premium plans) |\n| Adding money to your wallet | Free (minimum {{deposit_min}}) |\n"
                ."| Withdrawing | {{withdrawal_fee}} per withdrawal (minimum {{withdrawal_min}}) |\n| Pay-per-visit campaigns | {{campaign_fee}}% of each paid visit |\n\n"
                .'Amounts are in the currency of the transaction.',
                "| الخدمة | الرسوم |\n|---|---|\n| نشر مشروع أو طلب مؤثرين | مجاناً |\n| إرسال العروض | مجاناً ({{free_offers}} عرضاً شهرياً في الباقة المجانية) |\n"
                ."| الدفعات التي تستلمها مقابل العمل | {{site_fee}}% (أقل في الباقات المميزة) |\n| إضافة رصيد إلى محفظتك | مجاناً (الحد الأدنى {{deposit_min}}) |\n"
                ."| السحب | {{withdrawal_fee}} لكل عملية سحب (الحد الأدنى {{withdrawal_min}}) |\n| حملات الدفع لكل زيارة | {{campaign_fee}}% من كل زيارة مدفوعة |\n\n"
                .'المبالغ بعملة العملية.'],

            ['support', 'help', 'Help & support', 'المساعدة والدعم',
                "Most answers are in the [guide](/guide) and the pages about [payment protection](/payment-protection) and [disputes](/disputes-system).\n\n"
                .'Still need help? [Contact us](/contact) and our team will reply by email.',
                "ستجد معظم الإجابات في [دليل الاستخدام](/guide) وصفحتي [حماية الدفع](/payment-protection) و[النزاعات](/disputes-system).\n\n"
                .'ما زلت بحاجة إلى مساعدة؟ [تواصل معنا](/contact) وسيرد فريقنا عبر البريد الإلكتروني.'],

            ['contact', 'help', 'Contact us', 'تواصل معنا',
                'Write to us about anything: your account, a payment, a dispute or a partnership. We usually reply within one business day.',
                'راسلنا بخصوص أي شيء: حسابك أو دفعة أو نزاع أو شراكة. نرد عادة خلال يوم عمل واحد.'],

            ['terms', 'legal', 'Terms & conditions', 'الشروط والأحكام',
                self::DRAFT_EN."By using {{site_name}} you agree to these terms.\n\n## Accounts\nYou must give true information and keep your password safe. One person, one account.\n\n"
                ."## Payments\nWork agreed on the platform must be paid on the platform. Fees are listed on the [site fees](/site-fees) page.\n\n"
                ."## Conduct\nNo fraud, spam, fake reviews or abuse. We may suspend accounts that break these terms.\n\n"
                ."## Disputes\nDisagreements about milestones are settled through the [disputes system](/disputes-system).",
                self::DRAFT_AR."باستخدامك {{site_name}} فإنك توافق على هذه الشروط.\n\n## الحسابات\nيجب تقديم معلومات صحيحة والحفاظ على كلمة المرور. شخص واحد، حساب واحد.\n\n"
                ."## المدفوعات\nيجب دفع العمل المتفق عليه عبر المنصة. الرسوم موضحة في صفحة [رسوم الموقع](/site-fees).\n\n"
                ."## السلوك\nيُمنع الاحتيال والرسائل المزعجة والتقييمات المزيفة والإساءة، وقد نعلّق الحسابات المخالفة.\n\n"
                ."## النزاعات\nتُحسم الخلافات حول المراحل عبر [نظام النزاعات](/disputes-system)."],

            ['privacy-policy', 'legal', 'Privacy policy', 'سياسة الخصوصية',
                self::DRAFT_EN."## What we collect\nYour account details (name, email or phone), your profile, your projects, messages and payments, and technical data such as your device and IP address.\n\n"
                ."## Why\nTo run the service, keep payments safe, prevent fraud and, if you agree, send you news.\n\n"
                ."## Identity documents\nDocuments sent for verification are stored privately, are only seen by our verification team, and their numbers are encrypted.\n\n"
                ."## Your choices\nYou can edit your profile and notification settings at any time, and ask us to delete your account.",
                self::DRAFT_AR."## ما الذي نجمعه\nبيانات حسابك (الاسم والبريد أو الهاتف)، وملفك الشخصي، ومشاريعك، ورسائلك ومدفوعاتك، وبيانات تقنية مثل جهازك وعنوان IP.\n\n"
                ."## لماذا\nلتشغيل الخدمة، وحماية المدفوعات، ومنع الاحتيال، وإرسال الأخبار إذا وافقت.\n\n"
                ."## مستندات الهوية\nتُحفظ مستندات التوثيق بشكل خاص، ولا يطّلع عليها إلا فريق التوثيق، وأرقامها مشفّرة.\n\n"
                ."## خياراتك\nيمكنك تعديل ملفك وإعدادات الإشعارات في أي وقت، وطلب حذف حسابك."],

            ['user-agreement', 'legal', 'User agreement', 'اتفاقية المستخدم',
                self::DRAFT_EN.'This agreement is between you and {{site_name}}. It covers how you use the platform with other users: posting projects, sending offers, '
                .'working through milestones and paying through escrow. It works together with the [terms](/terms) and the [privacy policy](/privacy-policy).',
                self::DRAFT_AR.'هذه الاتفاقية بينك وبين {{site_name}}، وتغطي طريقة استخدامك للمنصة مع المستخدمين الآخرين: نشر المشاريع وإرسال العروض '
                .'والعمل عبر المراحل والدفع عبر الضمان. وهي مكمّلة لـ[الشروط](/terms) و[سياسة الخصوصية](/privacy-policy).'],
        ];
    }
}
