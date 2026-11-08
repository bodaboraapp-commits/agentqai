<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Page;
use App\Support\Seo;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;

/**
 * Machine-readable SEO/GEO endpoints: robots.txt, sitemap.xml and llms.txt.
 *
 * They are generated per installation (APP_URL, uploaded logo, editable pages,
 * FAQs), so a static public/robots.txt could never point at the right sitemap.
 */
class SeoController extends Controller
{
    /** Paths that must not be crawled or indexed. */
    private const DISALLOW = [
        '/dashboard',
        '/settings',
        '/admin',
        '/install',
        '/login',
        '/register',
        '/forgot-password',
        '/reset-password',
        '/verify-email',
        '/two-factor-challenge',
        '/user',
        '/api/',
        '/storage/',
    ];

    /** AI / agent crawlers that should be explicitly welcomed. */
    private const AI_CRAWLERS = [
        'GPTBot',
        'OAI-SearchBot',
        'ChatGPT-User',
        'ClaudeBot',
        'Claude-Web',
        'anthropic-ai',
        'PerplexityBot',
        'Google-Extended',
        'Applebot-Extended',
        'CCBot',
        'Amazonbot',
        'Bytespider',
        'YouBot',
    ];

    public function robots(): Response
    {
        $sitemap = Seo::siteUrl().'/sitemap.xml';

        $lines = [
            '# robots.txt — '.Seo::siteName(),
            '',
            'User-agent: *',
            'Allow: /',
        ];

        foreach (self::DISALLOW as $path) {
            $lines[] = 'Disallow: '.$path;
        }

        $lines[] = '';
        $lines[] = 'Sitemap: '.$sitemap;
        $lines[] = '';

        // Explicit groups for the major AI crawlers. A crawler that matches a
        // named group ignores the "*" group, so each one repeats the same
        // rules rather than inheriting the disallow list.
        foreach (self::AI_CRAWLERS as $crawler) {
            $lines[] = 'User-agent: '.$crawler;
            $lines[] = 'Allow: /';
            foreach (self::DISALLOW as $path) {
                $lines[] = 'Disallow: '.$path;
            }
            $lines[] = '';
        }

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function sitemap(): Response
    {
        $home = Seo::siteUrl();
        $settings = Seo::settings();
        $now = now()->toAtomString();

        $urls = [];

        $urls[] = [
            'loc' => $home.'/',
            'lastmod' => $settings?->updated_at?->toAtomString() ?? $now,
            'changefreq' => 'daily',
            'priority' => '1.0',
        ];

        $urls[] = [
            'loc' => $home.'/documentation',
            'lastmod' => $now,
            'changefreq' => 'monthly',
            'priority' => '0.8',
        ];

        $pages = rescue(fn () => Page::active()->orderBy('id')->get(['slug', 'updated_at']), collect(), false);

        foreach ($pages as $page) {
            $urls[] = [
                'loc' => $home.'/page/'.$page->slug,
                'lastmod' => $page->updated_at?->toAtomString() ?? $now,
                'changefreq' => 'monthly',
                'priority' => '0.6',
            ];
        }

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n";
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";

        foreach ($urls as $url) {
            $xml .= '  <url>'."\n";
            $xml .= '    <loc>'.e($url['loc']).'</loc>'."\n";

            if (($url['lastmod'] ?? '') !== '') {
                $xml .= '    <lastmod>'.e($url['lastmod']).'</lastmod>'."\n";
            }

            $xml .= '    <changefreq>'.$url['changefreq'].'</changefreq>'."\n";
            $xml .= '    <priority>'.$url['priority'].'</priority>'."\n";
            $xml .= '  </url>'."\n";
        }

        $xml .= '</urlset>'."\n";

        return response($xml, 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    /**
     * llms.txt — the emerging convention curated for language models and
     * autonomous agents (https://llmstxt.org/): concise markdown with a
     * summary and links to the canonical, crawlable resources.
     */
    public function llms(): Response
    {
        $home = Seo::siteUrl();
        $settings = Seo::settings();
        $name = Seo::siteName();
        $tagline = trim((string) ($settings?->site_tagline ?? ''));
        $description = trim((string) ($settings?->meta_description ?? ''));

        if ($description === '') {
            $description = $tagline !== ''
                ? $tagline
                : $name.' is an AI-powered telecommunications platform for automated calling campaigns, AI voice agents, SMS, contact management and customer engagement.';
        }

        $lines = ['# '.$name, '', '> '.$description, ''];

        if ($tagline !== '' && $tagline !== $description) {
            $lines[] = $tagline;
            $lines[] = '';
        }

        $lines[] = 'Canonical site: '.$home.'/';
        $lines[] = '';

        $lines[] = '## Pages';
        $lines[] = '- ['.$name.']('.$home.'/): product overview, capabilities, pricing and FAQ.';
        $lines[] = '- [Documentation]('.$home.'/documentation): installation, campaigns, telephony, AI agents, API and troubleshooting.';

        $pages = rescue(fn () => Page::active()->orderBy('id')->get(['slug', 'title']), collect(), false);
        foreach ($pages as $page) {
            $lines[] = '- ['.trim((string) $page->title).']('.$home.'/page/'.$page->slug.')';
        }

        $lines[] = '';

        $features = rescue(fn () => \App\Models\ThemeFeature::query()->where('is_active', true)->orderBy('order')->take(12)->get(['title', 'description']), collect(), false);
        if ($features->isNotEmpty()) {
            $lines[] = '## Capabilities';
            foreach ($features as $feature) {
                $title = trim((string) $feature->title);
                if ($title === '') {
                    continue;
                }
                $summary = trim(strip_tags((string) $feature->description));
                $lines[] = '- '.$title.($summary !== '' ? ': '.$summary : '');
            }
            $lines[] = '';
        }

        $pricing = rescue(fn () => \App\Models\ThemePricing::query()->where('is_active', true)->orderBy('order')->get(['credits', 'price']), collect(), false);
        if ($pricing->isNotEmpty()) {
            $lines[] = '## Pricing';
            foreach ($pricing as $plan) {
                $credits = (string) ($plan->credits ?? '');
                $price = (string) ($plan->price ?? '');
                if ($credits !== '' && $price !== '') {
                    $lines[] = '- '.$credits.' credits — '.$price;
                }
            }
            $lines[] = '';
        }

        $faqs = rescue(fn () => \App\Models\ThemeFaq::getActive()->take(12), collect(), false);
        if ($faqs->isNotEmpty()) {
            $lines[] = '## FAQ';
            foreach ($faqs as $faq) {
                $question = trim(strip_tags((string) $faq->question));
                $answer = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $faq->answer)) ?? '');
                if ($question !== '' && $answer !== '') {
                    $lines[] = '### '.$question;
                    $lines[] = $answer;
                    $lines[] = '';
                }
            }
        }

        $apiDocs = $this->apiDocsUrl();
        $lines[] = '## For agents and developers';
        if ($apiDocs !== null) {
            $lines[] = '- [REST API reference (OpenAPI)]('.$apiDocs.')';
        }
        $lines[] = '- [Sitemap]('.$home.'/sitemap.xml)';
        $lines[] = '- [robots.txt]('.$home.'/robots.txt)';
        $lines[] = '- Contact: '.$home.'/documentation';
        $lines[] = '';

        return response(implode("\n", $lines), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    private function apiDocsUrl(): ?string
    {
        foreach (['l5-swagger.default.api', 'l5-swagger.default.docs', 'l5-swagger.api'] as $name) {
            if (Route::has($name)) {
                return route($name);
            }
        }

        return file_exists(storage_path('api-docs/api-docs.json')) ? url('/api/documentation') : null;
    }
}
