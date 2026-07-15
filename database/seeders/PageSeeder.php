<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Page;

class PageSeeder extends Seeder
{
    public function run(): void
    {
        // HOME PAGE
        Page::updateOrCreate(
            ['slug' => 'home'],
            [
                'parent_page_id' => null,
                'album_id' => 1,
                'name' => 'Home',
                'label' => 'Home',
                'contents' => '<div style="max-width: 1100px; margin: auto;">
<h2 style="text-align: center; margin-bottom: 20px; font-size: 32px;">What We Offer</h2>
<p style="text-align: center; max-width: 700px; margin: 0 auto 50px; color: #475569; font-size: 16px;">We provide end-to-end solutions designed to help businesses build reliable, scalable, and user-focused digital products.</p>
<div style="display: flex; gap: 28px; flex-wrap: wrap;"><!-- Offer 1 -->
<div style="flex: 1; min-width: 260px; background: #ffffff; padding: 32px; border-radius: 10px; box-shadow: 0 6px 16px rgba(0,0,0,0.08);">
<h3 style="margin-bottom: 12px;">🚀 High Performance Systems</h3>
<p style="color: #475569; line-height: 1.6;">Optimized applications built for speed, scalability, and long-term reliability under real-world workloads.</p>
</div>
<div style="flex: 1; min-width: 260px; background: #ffffff; padding: 32px; border-radius: 10px; box-shadow: 0 6px 16px rgba(0,0,0,0.08);">
<h3 style="margin-bottom: 12px;">🔒 Secure Architecture</h3>
<p style="color: #475569; line-height: 1.6;">Security-first development using best practices to protect data, users, and business operations.</p>
</div>
<div style="flex: 1; min-width: 260px; background: #ffffff; padding: 32px; border-radius: 10px; box-shadow: 0 6px 16px rgba(0,0,0,0.08);">
<h3 style="margin-bottom: 12px;">🎨 Modern UI &amp; UX</h3>
<p style="color: #475569; line-height: 1.6;">Clean, intuitive interfaces designed to improve usability, engagement, and user satisfaction.</p>
</div>
<div style="flex: 1; min-width: 260px; background: #ffffff; padding: 32px; border-radius: 10px; box-shadow: 0 6px 16px rgba(0,0,0,0.08);">
<h3 style="margin-bottom: 12px;">⚙️ Custom Development</h3>
<p style="color: #475569; line-height: 1.6;">Tailored solutions built around your business needs, workflows, and long-term goals.</p>
</div>
<div style="flex: 1; min-width: 260px; background: #ffffff; padding: 32px; border-radius: 10px; box-shadow: 0 6px 16px rgba(0,0,0,0.08);">
<h3 style="margin-bottom: 12px;">☁️ Cloud &amp; Deployment</h3>
<p style="color: #475569; line-height: 1.6;">Scalable cloud infrastructure, CI/CD pipelines, and deployment strategies that just work.</p>
</div>
<div style="flex: 1; min-width: 260px; background: #ffffff; padding: 32px; border-radius: 10px; box-shadow: 0 6px 16px rgba(0,0,0,0.08);">
<h3 style="margin-bottom: 12px;">🛠 Ongoing Support</h3>
<p style="color: #475569; line-height: 1.6;">Continuous maintenance, monitoring, and improvements to keep your system healthy.</p>
</div>
</div>
</div>',
                'status' => 'published',
                'page_type' => 'default',
                'user_id' => 1,
            ]
        );

        // ABOUT PAGE
        Page::updateOrCreate(
            ['slug' => 'about'],
            [
                'parent_page_id' => null,
                'album_id' => null,
                'name' => 'About',
                'label' => 'About Us',
                'contents' => '<h1 style="color: #0056b3; border-bottom: 2px solid rgb(0, 86, 179); padding-bottom: 5px; font-family: Arial, sans-serif; text-align: center;"><img style="display: block; margin-left: auto; margin-right: auto;" src="https://encrypted-tbn0.gstatic.com/images?q=tbn:ANd9GcQdbk6PtQqqKgVprlkd1tzekP3ufz1tCW8q_w&amp;s" alt="" width="54" height="54"> About WebFocus Solutions, Inc.</h1>
<p style="font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #333;"><strong>WebFocus Solutions, Inc.</strong> is a Philippines-based IT company founded in <strong>2001</strong>, headquartered in Pasig City, at the Antel Global Corporate Center, Ortigas Center.</p>
<h2 style="color: #007bff; font-family: Arial, sans-serif; margin-top: 20px;">Services &amp; Capabilities</h2>
<ul style="font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; padding-left: 20px;">
<li><strong>Web Design &amp; Development:</strong> Custom websites and web applications tailored to client needs.</li>
<li><strong>Web Hosting &amp; Domains:</strong> Domain registration, cloud hosting, dedicated servers, and managed hosting.</li>
<li><strong>Managed IT Services:</strong> Ongoing IT support and infrastructure management.</li>
<li><strong>E-Commerce Solutions:</strong> Custom online stores and e-commerce platforms.</li>
<li><strong>Document Management Systems:</strong> Secure storage and management of enterprise documents.</li>
</ul>
<h2 style="color: #007bff; font-family: Arial, sans-serif; margin-top: 20px;">Approach &amp; Values</h2>
<ul style="font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; padding-left: 20px;">
<li>Focus on <strong>custom solutions</strong> rather than one-size-fits-all templates.</li>
<li>Emphasis on <strong>security and reliability</strong> for client websites and applications.</li>
<li>Prioritizes <strong>customer support</strong> with 24/7 assistance for hosting and managed services.</li>
<li>Committed to <strong>innovation</strong> and up-to-date technology stacks.</li>
<li>Seeks <strong>return on investment (ROI)</strong> for clients through effective web solutions.</li>
</ul>
<h2 style="color: #007bff; font-family: Arial, sans-serif; margin-top: 20px;">Reputation &amp; Strengths</h2>
<ul style="font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; padding-left: 20px;">
<li>Over two decades of experience in IT services.</li>
<li>Serving over 1,600 clients, from SMEs to large enterprises.</li>
<li>Offers an all-in-one service: hosting, domains, web development, and e-commerce solutions.</li>
<li>Customizable solutions tailored to client requirements.</li>
<li>Local support with knowledge of Philippine business environment.</li>
</ul>
<h2 style="color: #007bff; font-family: Arial, sans-serif; margin-top: 20px;">Considerations</h2>
<ul style="font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; padding-left: 20px;">
<li>Shared hosting uptime may be slightly below industry average; no formal SLA for some plans.</li>
<li>Some employee reviews mention limited benefits and growth opportunities.</li>
<li>Enterprise-scale projects may require verifying infrastructure capabilities.</li>
</ul>
<h2 style="color: #007bff; font-family: Arial, sans-serif; margin-top: 20px;">Quick Facts</h2>
<table style="border-collapse: collapse; width: 100%; font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; margin-bottom: 15px;">
<tbody>
<tr>
<td style="border: 1px solid #ccc; padding: 8px;"><strong>Founded</strong></td>
<td style="border: 1px solid #ccc; padding: 8px;">2001</td>
</tr>
<tr>
<td style="border: 1px solid #ccc; padding: 8px;"><strong>Headquarters</strong></td>
<td style="border: 1px solid #ccc; padding: 8px;">Pasig City, Philippines</td>
</tr>
<tr>
<td style="border: 1px solid #ccc; padding: 8px;"><strong>Number of Clients</strong></td>
<td style="border: 1px solid #ccc; padding: 8px;">1,600+</td>
</tr>
<tr>
<td style="border: 1px solid #ccc; padding: 8px;"><strong>Company Size</strong></td>
<td style="border: 1px solid #ccc; padding: 8px;">51&ndash;100 employees</td>
</tr>
<tr>
<td style="border: 1px solid #ccc; padding: 8px;"><strong>Website</strong></td>
<td style="border: 1px solid #ccc; padding: 8px;"><a style="color: #007bff; text-decoration: none;" href="https://www.webfocus.ph" target="_blank" rel="noopener">webfocus.ph</a></td>
</tr>
</tbody>
</table>
<p style="font-family: Arial, sans-serif; font-size: 16px; line-height: 1.6; color: #333;">🎯 <strong>WebFocus Solutions</strong> is a trusted partner for SMEs and organizations seeking reliable, custom web solutions, IT services, and online business support in the Philippines.</p>',
                'status' => 'published',
                'page_type' => 'standard',
                'user_id' => 1,
            ]
        );

        Page::updateOrCreate(
            ['slug' => 'footer'],
            [
                'parent_page_id' => null,
                'album_id' => null,
                'name' => 'Footer',
                'label' => 'Footer',
                'contents' => '
        <footer style="background-color:#212529;color:#ffffff;padding:24px 0;">
        <div style="max-width:1140px;margin:0 auto;padding:0 16px;">
            <div style="display:flex;flex-wrap:wrap;align-items:center;">

            <div style="flex:1 1 50%;margin-bottom:16px;">
                <h5 style="font-weight:bold;margin:0 0 4px 0;">
                Cms5
                </h5>
                <p style="font-size:14px;color:#adb5bd;margin:0;">
                © 2026 Cms5. All rights reserved.
                </p>
            </div>

            <div style="flex:1 1 50%;text-align:right;">
                <a href="/privacy" style="color:#ffffff;text-decoration:none;margin-right:16px;">
                Privacy Policy
                </a>
                <a href="/terms" style="color:#ffffff;text-decoration:none;">
                Terms of Service
                </a>
            </div>

            </div>
        </div>
        </footer>
        ',
                'status' => 'published',
                'page_type' => 'default',
                'user_id' => 1,
            ]
        );

        Page::updateOrCreate(
            ['slug' => 'news'],
            [
                'parent_page_id' => null,
                'album_id' => null,
                'name' => 'News',
                'label' => 'News',
                'contents' => '',
                'status' => 'published',
                'page_type' => 'default',
                'user_id' => 1,
            ]
        );

        Page::updateOrCreate(
            ['slug' => 'contact-us'],
            [
                'parent_page_id' => null,
                'album_id' => null,
                'name' => 'Contact Us',
                'label' => 'Contact Us',
                'contents' => '',
                'status' => 'published',
                'page_type' => 'default',
                'user_id' => 1,
            ]
        );
    }
}
