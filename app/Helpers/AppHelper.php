<?php

declare(strict_types=1);

namespace App\Helpers;

use App\Models\ThemeSetting;
use Illuminate\Support\Facades\Storage;

class AppHelper
{
    /**
     * Get application logo URL from ThemeSetting
     * 
     * @return string|null Logo URL or null if not set
     */
    public static function getLogoUrl(): ?string
    {
        try {
            $settings = ThemeSetting::first();
            
            if ($settings && $settings->logo_path) {
                // Use the logo_url accessor which handles the full URL generation
                return $settings->logo_url;
            }
        } catch (\Exception $e) {
            // Table might not exist during installation
            \Log::debug('Failed to fetch logo from ThemeSetting: ' . $e->getMessage());
        }
        
        return null;
    }

    /**
     * Get the dark-mode application logo URL from ThemeSetting
     *
     * @return string|null Dark logo URL or null if not set
     */
    public static function getLogoDarkUrl(): ?string
    {
        try {
            $settings = ThemeSetting::first();

            if ($settings && $settings->logo_dark_path) {
                return $settings->logo_dark_url;
            }
        } catch (\Exception $e) {
            \Log::debug('Failed to fetch dark logo from ThemeSetting: ' . $e->getMessage());
        }

        return null;
    }

    /**
     * Full branding config shared with the frontend: name, both logos, and
     * the per-surface display mode + size for landing / dashboard / auth.
     *
     * @return array<string, mixed>
     */
    public static function getBrandConfig(): array
    {
        $light = self::getLogoUrl();
        $dark = self::getLogoDarkUrl();
        $hasLogo = (bool) $light || (bool) $dark;

        // A configured logo is assumed to be the complete wordmark, so the
        // separate name text is not repeated next to it. Without a logo the
        // fallback mark + name text render as before. (Previously the default
        // "logo_text" mode printed the name twice when the uploaded logo
        // already spelled it out: once inside the image, once as text.)
        $defaultMode = $hasLogo ? 'logo_only' : 'logo_text';

        $defaults = [
            'landing' => ['mode' => $defaultMode, 'size' => 34],
            'dashboard' => ['mode' => $defaultMode, 'size' => 32],
            'auth' => ['mode' => $defaultMode, 'size' => 40],
        ];

        try {
            $s = ThemeSetting::first();
            if ($s) {
                foreach (['landing', 'dashboard', 'auth'] as $surface) {
                    // Only a deliberate non-default choice is honoured; the
                    // column default "logo_text" keeps the logo-only behaviour
                    // above. "text_only" stays available for logo-less styling.
                    $mode = $s->{"logo_display_{$surface}"} ?? null;
                    if (in_array($mode, ['logo_only', 'text_only'], true)) {
                        $defaults[$surface]['mode'] = $mode;
                    }
                    $size = $s->{"logo_size_{$surface}"} ?? null;
                    if ($size) {
                        $defaults[$surface]['size'] = (int) $size;
                    }
                }
            }
        } catch (\Exception $e) {
            \Log::debug('Failed to fetch brand config from ThemeSetting: ' . $e->getMessage());
        }

        return [
            'name' => self::getAppName(),
            'logoLight' => $light,
            'logoDark' => $dark ?: $light,
            'landing' => $defaults['landing'],
            'dashboard' => $defaults['dashboard'],
            'auth' => $defaults['auth'],
        ];
    }

    /**
     * Get application favicon URL
     *
     * @return string Favicon URL (defaults to favicon.ico if not set)
     */
    public static function getFaviconUrl(): string
    {
        try {
            $settings = ThemeSetting::first();
            
            if ($settings) {
                // Use the favicon_url accessor which handles the full URL generation
                return $settings->favicon_url;
            }
        } catch (\Exception $e) {
            // Table might not exist during installation
            \Log::debug('Failed to fetch favicon from ThemeSetting: ' . $e->getMessage());
        }
        
        return asset('favicon.ico');
    }

    /**
     * Get application name
     * 
     * @return string Application name
     */
    public static function getAppName(): string
    {
        try {
            $settings = ThemeSetting::first();
            
            if ($settings && $settings->site_name) {
                return $settings->site_name;
            }
        } catch (\Exception $e) {
            \Log::debug('Failed to fetch app name from ThemeSetting: ' . $e->getMessage());
        }
        
        return config('app.name', 'AgentQ AI');
    }
}
