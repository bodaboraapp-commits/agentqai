<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ rescue(fn () => \App\Models\Language::where('code', app()->getLocale())->value('is_rtl'), false, false) ? 'rtl' : 'ltr' }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        @php
            // Server-rendered SEO / GEO data. The SPA renders client-side, so
            // this is the only title, description, canonical and structured
            // data that non-JS crawlers (and therefore most AI crawlers) see.
            $seo = \App\Support\Seo::forPage($page ?? null);
        @endphp

        {{-- Inline script to handle theme --}}
        <script>
            (function() {
                // Check localStorage for theme preference
                const savedTheme = localStorage.getItem('theme');
                const prefersDark = window.matchMedia('(prefers-color-scheme: dark)').matches;

                // Apply dark mode if saved or if system prefers dark and no preference saved
                if (savedTheme === 'dark' || (!savedTheme && prefersDark)) {
                    document.documentElement.classList.add('dark');
                }
            })();
        </script>

        {{-- Inline style to set the HTML background color for both themes --}}
        <style>
            html {
                background-color: hsl(0, 0%, 100%);
            }

            html.dark {
                background-color: hsl(222.2, 84%, 4.9%);
            }
        </style>

        <title inertia>{{ $seo['title'] }}</title>

        {{-- Core SEO tags --}}
        <meta name="description" content="{{ $seo['description'] }}">
        @if($seo['keywords'] !== '')
            <meta name="keywords" content="{{ $seo['keywords'] }}">
        @endif
        <meta name="robots" content="{{ $seo['robots'] }}">
        <meta name="googlebot" content="{{ $seo['robots'] }}">
        <meta name="author" content="{{ $seo['site_name'] }}">
        <link rel="canonical" href="{{ $seo['canonical'] }}">

        {{-- Open Graph --}}
        <meta property="og:site_name" content="{{ $seo['site_name'] }}">
        <meta property="og:type" content="{{ $seo['og_type'] }}">
        <meta property="og:title" content="{{ $seo['title'] }}">
        <meta property="og:description" content="{{ $seo['description'] }}">
        <meta property="og:url" content="{{ $seo['canonical'] }}">
        @if($seo['og_image'] !== '')
            <meta property="og:image" content="{{ $seo['og_image'] }}">
            <meta property="og:image:alt" content="{{ $seo['site_name'] }}">
        @endif

        {{-- Twitter / X --}}
        <meta name="twitter:card" content="{{ $seo['twitter_card'] }}">
        @if($seo['twitter_site'] !== '')
            <meta name="twitter:site" content="{{ $seo['twitter_site'] }}">
        @endif
        <meta name="twitter:title" content="{{ $seo['title'] }}">
        <meta name="twitter:description" content="{{ $seo['description'] }}">
        @if($seo['og_image'] !== '')
            <meta name="twitter:image" content="{{ $seo['og_image'] }}">
        @endif

        {{-- Structured data for search + AI overviews: Organization, WebSite,
             SoftwareApplication, FAQPage, Article and BreadcrumbList. --}}
        {!! $seo['json_ld'] !!}

        @php
            // The installer renders through this view before any database
            // exists; a failed lookup must degrade to "no settings", not a 500.
            $themeSettings = rescue(fn () => \App\Models\ThemeSetting::first(), null, false);
        @endphp

        @if($themeSettings)
            {{-- Google Analytics --}}
            @if($themeSettings->google_analytics_id)
                <script async src="https://www.googletagmanager.com/gtag/js?id={{ $themeSettings->google_analytics_id }}"></script>
                <script>
                    window.dataLayer = window.dataLayer || [];
                    function gtag(){dataLayer.push(arguments);}
                    gtag('js', new Date());
                    gtag('config', '{{ $themeSettings->google_analytics_id }}');
                </script>
            @endif

            {{-- Google Tag Manager --}}
            @if($themeSettings->google_tag_manager_id)
                <script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
                new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
                j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
                'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
                })(window,document,'script','dataLayer','{{ $themeSettings->google_tag_manager_id }}');</script>
            @endif

            {{-- Facebook Pixel --}}
            @if($themeSettings->facebook_pixel_id)
                <script>
                    !function(f,b,e,v,n,t,s)
                    {if(f.fbq)return;n=f.fbq=function(){n.callMethod?
                    n.callMethod.apply(n,arguments):n.queue.push(arguments)};
                    if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';
                    n.queue=[];t=b.createElement(e);t.async=!0;
                    t.src=v;s=b.getElementsByTagName(e)[0];
                    s.parentNode.insertBefore(t,s)}(window, document,'script',
                    'https://connect.facebook.net/en_US/fbevents.js');
                    fbq('init', '{{ $themeSettings->facebook_pixel_id }}');
                    fbq('track', 'PageView');
                </script>
                <noscript>
                    <img height="1" width="1" style="display:none"
                         src="https://www.facebook.com/tr?id={{ $themeSettings->facebook_pixel_id }}&ev=PageView&noscript=1"/>
                </noscript>
            @endif

            {{-- Custom Head Scripts --}}
            @if($themeSettings->custom_head_scripts)
                {!! $themeSettings->custom_head_scripts !!}
            @endif
        @endif

        {{-- Favicon --}}
        <link rel="icon" type="image/x-icon" href="{{ \App\Helpers\AppHelper::getFaviconUrl() }}">
        <link rel="shortcut icon" type="image/x-icon" href="{{ \App\Helpers\AppHelper::getFaviconUrl() }}">
        <link rel="apple-touch-icon" href="{{ \App\Helpers\AppHelper::getFaviconUrl() }}">
        <link rel="icon" type="image/svg+xml" href="{{ \App\Helpers\AppHelper::getFaviconUrl() }}">

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700,800|inter:400,500,600,700,800,900|geist-mono:400,500,600,700" rel="stylesheet" />

        @viteReactRefresh
        @vite(['resources/js/app.tsx', "resources/js/pages/{$page['component']}.tsx"])
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        {{-- Server-rendered, human-readable summary for crawlers and AI agents
             that do not execute JavaScript. Never visible in a JS browser. --}}
        @if($seo['fallback'] !== '')
            <noscript>{!! $seo['fallback'] !!}</noscript>
        @endif

        {{-- Google Tag Manager (noscript) --}}
        @if($themeSettings && $themeSettings->google_tag_manager_id)
            <noscript><iframe src="https://www.googletagmanager.com/ns.html?id={{ $themeSettings->google_tag_manager_id }}"
            height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
        @endif

        @inertia

        {{-- Custom Body Scripts --}}
        @if($themeSettings && $themeSettings->custom_body_scripts)
            {!! $themeSettings->custom_body_scripts !!}
        @endif
    </body>
</html>
