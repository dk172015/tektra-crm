<?php

namespace Database\Seeders;

use App\Models\LeadSource;
use Illuminate\Database\Seeder;

class LeadSourceSeeder extends Seeder
{
    public function run(): void
    {
        $items = [
            ['code' => 'google_ads', 'name' => 'Google Ads'],
            ['code' => 'facebook_ads', 'name' => 'Facebook Ads'],
            ['code' => 'tiktok_ads', 'name' => 'TikTok Ads'],
            ['code' => 'zalo', 'name' => 'Zalo'],
            ['code' => 'website_organic', 'name' => 'Website Organic'],
            ['code' => 'seo', 'name' => 'SEO'],
            ['code' => 'referral', 'name' => 'Giới thiệu'],
            ['code' => 'hotline', 'name' => 'Hotline'],
            ['code' => 'walk_in', 'name' => 'Khách đến trực tiếp'],
            ['code' => 'sales_self_gen', 'name' => 'Sale tự tìm'],
            ['code' => 'partner', 'name' => 'Đối tác'],
            ['code' => 'other', 'name' => 'Khác'],
        ];

        foreach ($items as $item) {
            LeadSource::updateOrCreate(
                ['code' => $item['code']],
                [
                    'name' => $item['name'],
                    'is_active' => true,
                ]
            );
        }
    }
}