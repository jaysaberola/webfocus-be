<?php

namespace Database\Seeders;

use App\Models\Article;
use App\Models\ArticleCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class ArticleSeeder extends Seeder
{
    public function run(): void
    {
        $author = User::where('email', 'admin@wsi.com')->first()
            ?? User::query()->first();

        if (!$author) {
            $this->command?->warn('ArticleSeeder skipped: no users found.');
            return;
        }

        $frontendBase = rtrim(env('CORS_ALLOWED_ORIGIN', 'http://127.0.0.1:3000'), '/');

        $categories = [
            [
                'name' => 'Company News',
                'slug' => 'company-news',
            ],
            [
                'name' => 'Product Updates',
                'slug' => 'product-updates',
            ],
            [
                'name' => 'Industry Insights',
                'slug' => 'industry-insights',
            ],
        ];

        $categoryIds = [];
        foreach ($categories as $category) {
            $record = ArticleCategory::updateOrCreate(
                ['slug' => $category['slug']],
                [
                    'name' => $category['name'],
                    'user_id' => $author->id,
                ]
            );
            $categoryIds[$category['slug']] = $record->id;
        }

        $articles = [
            [
                'slug' => 'webfocus-expands-manila-noc-support',
                'name' => 'WebFocus Expands 24/7 Manila NOC Support for Enterprise Clients',
                'category' => 'company-news',
                'date' => '2026-07-10',
                'is_featured' => true,
                'image' => 'article.jpg',
                'teaser' => 'Our Quezon City operations center now offers round-the-clock monitoring, faster incident response, and proactive hosting health checks for Philippine enterprises.',
                'contents' => <<<'HTML'
<p>WebFocus Solutions, Inc. has expanded its Network Operations Center (NOC) capabilities to deliver true 24/7 support for cloud, dedicated, and bare-metal hosting clients across the Philippines.</p>
<p>The upgrade includes real-time infrastructure monitoring, automated failover alerts, and dedicated escalation paths for mission-critical workloads. Clients on shared and enterprise tiers can now expect faster ticket resolution and clearer SLA reporting.</p>
<figure><img src="{IMAGE}" alt="WebFocus NOC support team" style="max-width:100%;border-radius:12px;" /></figure>
<p>"Philippine businesses deserve hosting partners who respond before downtime becomes revenue loss," said the WebFocus operations team. "This expansion reflects our commitment to local, always-on technical care."</p>
<ul>
  <li>24/7 live monitoring for server, DNS, and mail uptime</li>
  <li>Priority routing for dedicated and bare-metal accounts</li>
  <li>Monthly health summaries for compliance and audit teams</li>
</ul>
HTML,
            ],
            [
                'slug' => 'new-shared-hosting-plans-2026',
                'name' => 'New Shared Hosting Plans Built for Growing PH SMEs',
                'category' => 'product-updates',
                'date' => '2026-06-28',
                'is_featured' => false,
                'image' => 'hosting.jpg',
                'teaser' => 'Starter through Business shared packages now include NVMe storage, improved bandwidth tiers, and optional SSL and backup add-ons.',
                'contents' => <<<'HTML'
<p>WebFocus has refreshed its shared hosting lineup with faster NVMe storage, clearer resource tiers, and flexible annual billing designed for startups and SMEs.</p>
<p>Whether you are launching a corporate brochure site or a lightweight product catalog, the updated plans make it easier to scale RAM and storage without migrating platforms.</p>
<figure><img src="{IMAGE}" alt="Shared hosting infrastructure" style="max-width:100%;border-radius:12px;" /></figure>
<p>Popular add-ons such as SiteLock, automated backups, and static IP allocation remain available from the Services catalogue.</p>
HTML,
            ],
            [
                'slug' => 'canvas-7-templates-for-corporate-sites',
                'name' => 'Canvas 7 Templates Accelerate Corporate Website Launches',
                'category' => 'product-updates',
                'date' => '2026-06-15',
                'is_featured' => false,
                'image' => 'construction.jpg',
                'teaser' => 'Choose from responsive Canvas 7 layouts for construction, professional services, hospitality, and e-commerce brands.',
                'contents' => <<<'HTML'
<p>Custom web design engagements now start faster with WebFocus Canvas 7 templates—mobile-ready layouts that can be branded, extended, and connected to CMS modules.</p>
<p>Agency packages cover starter launches, full corporate builds, and high-concurrency e-commerce experiences with payment gateway integration.</p>
<figure><img src="{IMAGE}" alt="Corporate website template preview" style="max-width:100%;border-radius:12px;" /></figure>
<p>Clients can preview sample layouts in the Services hub and request a Figma prototype before development begins.</p>
HTML,
            ],
            [
                'slug' => 'secure-domain-registrations-for-ph-brands',
                'name' => 'Secure .ph and Global Domain Registrations for Local Brands',
                'category' => 'industry-insights',
                'date' => '2026-05-30',
                'is_featured' => false,
                'image' => 'blog.jpg',
                'teaser' => 'Protect your brand with .com.ph, .ph, and hybrid TLD options backed by WebFocus DNS management and renewal reminders.',
                'contents' => <<<'HTML'
<p>A strong domain strategy remains one of the most cost-effective ways to establish credibility in the Philippine market. WebFocus supports global, hybrid, and country-level extensions with instant availability checks.</p>
<p>Teams can register primary brands, campaign microsites, and education domains from a single Services workflow.</p>
<figure><img src="{IMAGE}" alt="Domain registration search" style="max-width:100%;border-radius:12px;" /></figure>
<p>Pair domain registration with SSL certificates and managed DNS for a complete public-facing launch checklist.</p>
HTML,
            ],
            [
                'slug' => 'document-management-for-hybrid-teams',
                'name' => 'Document Management & Mail Suites for Hybrid Teams',
                'category' => 'industry-insights',
                'date' => '2026-05-12',
                'is_featured' => false,
                'image' => 'coworking.jpg',
                'teaser' => 'Standard and enterprise DMS tiers combine secure archival, role-based access, and business email integrations for modern workplaces.',
                'contents' => <<<'HTML'
<p>Hybrid teams need more than inboxes—they need searchable archives, permission controls, and audit trails. WebFocus DMS packages bundle Google and Microsoft mail options with enterprise archival suites.</p>
<p>From five-mailbox startups to unlimited LGU deployments, plans scale with your organization.</p>
<figure><img src="{IMAGE}" alt="Hybrid team collaboration" style="max-width:100%;border-radius:12px;" /></figure>
<p>Contact our solutions team to map retention policies, encryption requirements, and onboarding timelines.</p>
HTML,
            ],
            [
                'slug' => 'webfocus-wins-admired-brand-recognition',
                'name' => 'WebFocus Recognized as 2018 Admired Brand in IT Solutions',
                'category' => 'company-news',
                'date' => '2026-04-20',
                'is_featured' => false,
                'image' => 'yoga.jpg',
                'teaser' => 'The Admired Brand award highlights WebFocus excellence in web design, development, and managed IT services for Philippine organizations.',
                'contents' => <<<'HTML'
<p>WebFocus Solutions, Inc. continues to build on its reputation as a trusted partner for web design, development, and IT services in the Philippines.</p>
<p>Our team combines creative execution with secure hosting, domain services, and long-term client support—helping brands stay resilient as digital expectations evolve.</p>
<figure><img src="{IMAGE}" alt="WebFocus team celebration" style="max-width:100%;border-radius:12px;" /></figure>
<p>Explore our portfolio, services catalogue, and latest updates to see how we help organizations launch and grow online.</p>
HTML,
            ],
        ];

        foreach ($articles as $item) {
            $imagePath = "/images/news/{$item['image']}";
            $imageUrl = "{$frontendBase}{$imagePath}";
            $contents = str_replace('{IMAGE}', $imageUrl, $item['contents']);

            Article::updateOrCreate(
                ['slug' => $item['slug']],
                [
                    'category_id' => $categoryIds[$item['category']],
                    'name' => $item['name'],
                    'date' => $item['date'],
                    'teaser' => $item['teaser'],
                    'contents' => $contents,
                    'status' => 'published',
                    'is_featured' => $item['is_featured'],
                    'image_url' => $imagePath,
                    'thumbnail_url' => $imagePath,
                    'meta_title' => $item['name'],
                    'meta_description' => $item['teaser'],
                    'meta_keyword' => implode(', ', [
                        'WebFocus',
                        Str::headline(str_replace('-', ' ', $item['category'])),
                        'Philippines',
                        'IT',
                    ]),
                    'user_id' => $author->id,
                ]
            );
        }
    }
}
