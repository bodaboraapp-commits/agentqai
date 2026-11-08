<?php

declare(strict_types=1);

namespace App\Support;

use App\Helpers\AppHelper;
use App\Models\Page;
use App\Models\ThemeFaq;
use App\Models\ThemeFeature;
use App\Models\ThemeSetting;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Server-side SEO / GEO (generative engine optimisation) data for the Inertia
 * root view.
 *
 * The app is a client-rendered Inertia SPA, so without this the raw HTML a
 * crawler receives contains no title, description, canonical, structured data
 * or readable content — only a JSON payload most non-JS crawlers (and most AI
 * crawlers: GPTBot, ClaudeBot, PerplexityBot, …) never execute. Everything
 * here is rendered into the initial HTML response.
 *
 * Every database read is wrapped in rescue(): the installer renders through the
 * same root view before a database exists.
 */
final class Seo
{
    /** Public, indexable route names. */
    public const PUBLIC_ROUTES = ['home', 'documentation', 'page.show'];

    /** Machine-readable routes that are not HTML pages. */
    public const MACHINE_ROUTES = ['seo.robots', 'seo.sitemap', 'seo.llms'];

    private static ?ThemeSetting $settingsCache = null;

    private static bool $settingsLoaded = false;

    private static ?Collection $featuresCache = null;

    private static ?Collection $faqsCache = null;

    public static function isPublicPage(): bool
    {
        return in_array(request()->route()?->getName(), self::PUBLIC_ROUTES, true);
    }

    public static function settings(): ?ThemeSetting
    {
        if (! self::$settingsLoaded) {
            self::$settingsLoaded = true;
            self::$settingsCache = rescue(static fn (): ?ThemeSetting => ThemeSetting::first(), null, false);
        }

        return self::$settingsCache;
    }

    public static function siteName(): string
    {
        try {
            $name = trim(AppHelper::getAppName());

            return $name !== '' ? $name : (string) config('app.name', 'AgentQ AI');
        } catch (\Throwable) {
            return (string) config('app.name', 'AgentQ AI');
        }
    }

    public static function siteUrl(): string
    {
        return rtrim(url('/'), '/');
    }

    /**
     * Everything the root Blade view needs for one request.
     *
     * @param  array<string, mixed>|null  $inertiaPage  the `$page` array Inertia passes to the root view
     * @return array<string, string>
     */
    public static function forPage(?array $inertiaPage = null): array
    {
        $name = self::siteName();

        try {
            $settings = self::settings();
            $route = request()->route()?->getName();
            $public = in_array($route, self::PUBLIC_ROUTES, true);

            $page = self::resolvePage($inertiaPage);
            $tagline = self::clean($settings?->site_tagline);
            $siteDescription = self::clean($settings?->meta_description);
            $siteTitle = self::clean($settings?->meta_title);

            $canonical = self::canonicalUrl();
            $ogType = 'website';

            switch ($route) {
                case 'home':
                    $title = $siteTitle !== '' ? $siteTitle : trim($name.($tagline !== '' ? ' — '.$tagline : ''));
                    $description = $siteDescription !== '' ? $siteDescription : ($tagline !== '' ? $tagline : self::defaultDescription($name));
                    break;

                case 'documentation':
                    $title = ($siteTitle !== '' ? $siteTitle : $name).' Documentation';
                    $description = $siteDescription !== '' ? $siteDescription : 'Complete documentation for '.$name.': installation, campaigns, contacts, telephony, AI agents, API reference and troubleshooting.';
                    $ogType = 'article';
                    break;

                case 'page.show':
                    $pageTitle = self::clean($page['title'] ?? '');
                    $pageMetaTitle = self::clean($page['meta_title'] ?? '');
                    $pageMetaDescription = self::clean($page['meta_description'] ?? '');
                    $title = $pageMetaTitle !== '' ? $pageMetaTitle : (($pageTitle !== '' ? $pageTitle : $name).' — '.$name);
                    $description = $pageMetaDescription !== '' ? $pageMetaDescription : Str::limit(self::clean(strip_tags((string) ($page['content'] ?? ''))), 158);
                    $ogType = 'article';
                    break;

                default:
                    $title = $name;
                    $description = $siteDescription !== '' ? $siteDescription : $tagline;
                    break;
            }

            if ($description === '') {
                $description = self::defaultDescription($name);
            }

            $robots = $public
                ? 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1'
                : 'noindex, nofollow';

            $logo = self::absolute(trim((string) ($settings?->logo_url ?? '')));
            $ogImage = self::absolute(trim((string) ($settings?->og_image_url ?? ''))) ?: $logo;

            return [
                'title' => self::limit($title, 70),
                'description' => self::limit($description, 320),
                'keywords' => self::clean($settings?->meta_keywords),
                'canonical' => $canonical,
                'robots' => $robots,
                'site_name' => $name,
                'og_type' => $ogType,
                'og_image' => $ogImage,
                'twitter_card' => trim((string) ($settings?->twitter_card ?? '')) ?: 'summary_large_image',
                'twitter_site' => trim((string) ($settings?->twitter_site ?? '')),
                'json_ld' => self::jsonLdScript(self::jsonLdGraph($name, $description, $title, $canonical, $ogImage, $page, $settings)),
                'fallback' => $public ? self::fallbackHtml($name, $description) : '',
            ];
        } catch (\Throwable $e) {
            return [
                'title' => $name,
                'description' => self::defaultDescription($name),
                'keywords' => '',
                'canonical' => self::siteUrl(),
                'robots' => 'noindex, nofollow',
                'site_name' => $name,
                'og_type' => 'website',
                'og_image' => '',
                'twitter_card' => 'summary_large_image',
                'twitter_site' => '',
                'json_ld' => '',
                'fallback' => '',
            ];
        }
    }

    /**
     * @param  array<string, mixed>|null  $inertiaPage
     * @return array<string, mixed>
     */
    private static function resolvePage(?array $inertiaPage): array
    {
        if (request()->route()?->getName() !== 'page.show') {
            return [];
        }

        $props = $inertiaPage['props']['page'] ?? null;
        if (is_array($props)) {
            return $props;
        }

        $slug = (string) (request()->route('slug') ?? '');

        return $slug === '' ? [] : (array) rescue(fn () => Page::getBySlug($slug)?->toArray(), [], false);
    }

    private static function canonicalUrl(): string
    {
        $current = (string) url()->current();
        $site = self::siteUrl();

        // Never publish tracking parameters as the canonical URL.
        if (str_starts_with($current, $site)) {
            $path = substr($current, strlen($site));

            return $path === '' ? $site : $site.$path;
        }

        return $current !== '' ? $current : $site;
    }

    /**
     * @param  array<string, mixed>  $page
     * @return list<array<string, mixed>>
     */
    private static function jsonLdGraph(string $name, string $description, string $title, string $canonical, string $logo, array $page, ?ThemeSetting $settings): array
    {
        $home = self::siteUrl();
        $orgId = $home.'#organization';
        $websiteId = $home.'#website';
        $locale = str_replace('_', '-', (string) app()->getLocale());
        $route = request()->route()?->getName();

        $organization = [
            '@type' => 'Organization',
            '@id' => $orgId,
            'name' => $name,
            'url' => $home,
            'description' => $description,
        ];

        if ($logo !== '') {
            $organization['logo'] = ['@type' => 'ImageObject', 'url' => $logo];
        }

        $sameAs = self::socialLinks($settings);
        if ($sameAs !== []) {
            $organization['sameAs'] = $sameAs;
        }

        $graph = [
            $organization,
            [
                '@type' => 'WebSite',
                '@id' => $websiteId,
                'url' => $home,
                'name' => $name,
                'description' => $description,
                'inLanguage' => $locale,
                'publisher' => ['@id' => $orgId],
            ],
        ];

        if ($route === 'home') {
            $graph[] = [
                '@type' => 'WebPage',
                '@id' => $home.'#webpage',
                'url' => $home,
                'name' => $title,
                'description' => $description,
                'isPartOf' => ['@id' => $websiteId],
                'about' => ['@id' => $orgId],
                'inLanguage' => $locale,
            ];

            $application = [
                '@type' => 'SoftwareApplication',
                '@id' => $home.'#software',
                'name' => $name,
                'applicationCategory' => 'BusinessApplication',
                'operatingSystem' => 'Web',
                'url' => $home,
                'description' => $description,
            ];

            $features = self::features()
                ->pluck('title')
                ->filter(fn ($feature) => is_string($feature) && trim($feature) !== '')
                ->map(fn ($feature) => trim((string) $feature))
                ->values()
                ->all();

            if ($features !== []) {
                $application['featureList'] = $features;
            }

            $graph[] = $application;

            $faqs = self::faqs()
                ->filter(fn ($faq) => trim((string) $faq->question) !== '' && trim((string) $faq->answer) !== '')
                ->map(fn ($faq) => [
                    '@type' => 'Question',
                    'name' => self::clean((string) $faq->question),
                    'acceptedAnswer' => [
                        '@type' => 'Answer',
                        'text' => self::clean((string) $faq->answer),
                    ],
                ])
                ->values()
                ->all();

            if ($faqs !== []) {
                $graph[] = [
                    '@type' => 'FAQPage',
                    '@id' => $home.'#faq',
                    'mainEntity' => $faqs,
                ];
            }
        } elseif ($route === 'documentation') {
            $graph[] = [
                '@type' => 'WebPage',
                '@id' => $canonical.'#webpage',
                'url' => $canonical,
                'name' => $title,
                'description' => $description,
                'isPartOf' => ['@id' => $websiteId],
                'inLanguage' => $locale,
            ];
            $graph[] = self::breadcrumbs($home, [['Home', $home], ['Documentation', $canonical]]);
        } elseif ($route === 'page.show' && $page !== []) {
            $article = [
                '@type' => 'Article',
                '@id' => $canonical.'#article',
                'headline' => $title,
                'description' => $description,
                'mainEntityOfPage' => ['@id' => $canonical.'#webpage'],
                'publisher' => ['@id' => $orgId],
                'inLanguage' => $locale,
            ];

            $modified = $page['updated_at'] ?? null;
            if (is_string($modified) && $modified !== '') {
                $article['dateModified'] = $modified;
            }

            $graph[] = $article;
            $graph[] = [
                '@type' => 'WebPage',
                '@id' => $canonical.'#webpage',
                'url' => $canonical,
                'name' => $title,
                'description' => $description,
                'isPartOf' => ['@id' => $websiteId],
                'inLanguage' => $locale,
            ];
            $graph[] = self::breadcrumbs($home, [['Home', $home], [(string) ($page['title'] ?? 'Page'), $canonical]]);
        }

        return $graph;
    }

    /**
     * @param  list<array{0: string, 1: string}>  $items
     * @return array<string, mixed>
     */
    private static function breadcrumbs(string $home, array $items): array
    {
        $elements = [];
        foreach (array_values($items) as $position => [$label, $url]) {
            $elements[] = [
                '@type' => 'ListItem',
                'position' => $position + 1,
                'name' => $label,
                'item' => $url,
            ];
        }

        return [
            '@type' => 'BreadcrumbList',
            '@id' => $home.'#breadcrumb',
            'itemListElement' => $elements,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $graph
     */
    private static function jsonLdScript(array $graph): string
    {
        $json = json_encode(
            ['@context' => 'https://schema.org', '@graph' => $graph],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        );

        if ($json === false) {
            return '';
        }

        // Never let a "</" inside a string close the script element.
        $json = str_replace('</', '<\/', $json);

        return '<script type="application/ld+json">'.$json.'</script>';
    }

    /**
     * Readable, server-rendered summary inside <noscript>. Crawlers that never
     * execute JavaScript — which includes most AI/agent crawlers — see the
     * actual product name, description, capabilities, FAQ answers and links.
     */
    private static function fallbackHtml(string $name, string $description): string
    {
        $html = ['<div id="seo-fallback" style="max-width:56rem;margin:2rem auto;padding:0 1rem;font-family:system-ui,sans-serif;line-height:1.6">'];
        $html[] = '<h1>'.e($name).'</h1>';
        $html[] = '<p>'.e($description).'</p>';

        $features = self::features();
        if ($features->isNotEmpty()) {
            $html[] = '<h2>What '.e($name).' does</h2><ul>';
            foreach ($features as $feature) {
                $title = self::clean((string) $feature->title);
                if ($title === '') {
                    continue;
                }
                $html[] = '<li><strong>'.e($title).'</strong>'.($feature->description ? ': '.e(self::clean((string) $feature->description)) : '').'</li>';
            }
            $html[] = '</ul>';
        }

        $faqs = self::faqs();
        if ($faqs->isNotEmpty()) {
            $html[] = '<h2>Frequently asked questions</h2>';
            foreach ($faqs as $faq) {
                $question = self::clean((string) $faq->question);
                $answer = self::clean((string) $faq->answer);
                if ($question === '' || $answer === '') {
                    continue;
                }
                $html[] = '<h3>'.e($question).'</h3><p>'.e($answer).'</p>';
            }
        }

        $links = ['<a href="'.e(url('/documentation')).'">Documentation</a>'];

        $pages = rescue(fn () => Page::active()->orderBy('id')->get(['slug', 'title']), collect(), false);
        foreach ($pages as $page) {
            $links[] = '<a href="'.e(route('page.show', $page->slug)).'">'.e((string) $page->title).'</a>';
        }

        $html[] = '<p>Learn more: '.implode(' · ', $links).'</p>';
        $html[] = '</div>';

        return implode('', $html);
    }

    /**
     * @return Collection<int, ThemeFeature>
     */
    private static function features(): Collection
    {
        if (self::$featuresCache === null) {
            self::$featuresCache = rescue(
                fn () => ThemeFeature::query()->where('is_active', true)->orderBy('order')->take(12)->get(),
                collect(),
                false
            );
        }

        return self::$featuresCache;
    }

    /**
     * @return Collection<int, ThemeFaq>
     */
    private static function faqs(): Collection
    {
        if (self::$faqsCache === null) {
            self::$faqsCache = rescue(fn () => ThemeFaq::getActive()->take(12), collect(), false);
        }

        return self::$faqsCache;
    }

    /**
     * @return list<string>
     */
    private static function socialLinks(?ThemeSetting $settings): array
    {
        $links = $settings?->social_links;

        if (! is_array($links)) {
            return [];
        }

        $urls = [];
        foreach ($links as $value) {
            if (is_string($value) && filter_var($value, FILTER_VALIDATE_URL)) {
                $urls[] = $value;
            } elseif (is_array($value)) {
                $candidate = $value['url'] ?? $value['href'] ?? null;
                if (is_string($candidate) && filter_var($candidate, FILTER_VALIDATE_URL)) {
                    $urls[] = $candidate;
                }
            }
        }

        return array_values(array_unique($urls));
    }

    private static function defaultDescription(string $name): string
    {
        return $name.' is an AI-powered telecommunications platform for automated calling campaigns, AI voice agents, SMS, contact management and customer engagement.';
    }

    private static function absolute(string $url): string
    {
        if ($url === '') {
            return '';
        }

        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }

        return url($url);
    }

    private static function clean(?string $value): string
    {
        if ($value === null) {
            return '';
        }

        $value = strip_tags($value);
        $value = html_entity_decode($value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\s+/u', ' ', $value) ?? $value;

        return trim($value);
    }

    private static function limit(string $value, int $max): string
    {
        return Str::length($value) > $max ? Str::limit($value, $max, '…') : $value;
    }
}
