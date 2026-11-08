<?php

/**
 * Shared-hosting fallback front controller.
 *
 * Reached only when the web server's document root is the application folder
 * rather than public/ and mod_rewrite could not redirect into public/ (see
 * .htaccess). Static assets will not resolve in this state, so the installer
 * flags it on the requirements page; this still lets the page render instead
 * of exposing a directory listing.
 */
require __DIR__.'/public/index.php';
