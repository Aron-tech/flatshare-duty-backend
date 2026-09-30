<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * The public marketing pages (landing, privacy, account deletion) load plain CSS files
 * with no Vite build step, so they render without an npm build. This command minifies
 * the readable *.css sources in public/css into *.min.css, which the pages actually load.
 *
 * Run after editing public/css/public.css or public/css/landing.css.
 */
class BuildPublicCssCommand extends Command
{
    protected $signature = 'css:build-public';

    protected $description = 'Minify the public marketing pages\' CSS files (public/css/*.css → *.min.css)';

    public function handle(): int
    {
        $sources = collect(File::glob(public_path('css/*.css')))
            ->reject(fn (string $path): bool => str_ends_with($path, '.min.css'));

        foreach ($sources as $source) {
            $minified = $this->minify(File::get($source));
            $target = substr($source, 0, -strlen('.css')).'.min.css';
            File::put($target, $minified);

            $this->info(basename($source).' → '.basename($target).' ('.strlen($minified).' bytes)');
        }

        return self::SUCCESS;
    }

    private function minify(string $css): string
    {
        // Strip /* ... */ comments.
        $css = preg_replace('#/\*.*?\*/#s', '', $css) ?? $css;
        // Collapse all whitespace runs (including newlines) to a single space.
        $css = preg_replace('/\s+/', ' ', $css) ?? $css;
        // Drop the space around punctuation that never needs it in CSS.
        $css = preg_replace('/\s*([{}:;,])\s*/', '$1', $css) ?? $css;
        // A trailing semicolon before a closing brace is redundant.
        $css = str_replace(';}', '}', $css);

        return trim($css);
    }
}
