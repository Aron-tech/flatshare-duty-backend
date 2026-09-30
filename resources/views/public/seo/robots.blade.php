# Search engines and AI assistants (Google, Gemini, ChatGPT, Claude, Perplexity…) may index the public pages.
# The API is only for the mobile app.
@foreach (['Googlebot', 'Google-Extended', 'Bingbot', 'GPTBot', 'OAI-SearchBot', 'ChatGPT-User', 'ClaudeBot', 'Claude-SearchBot', 'Claude-User', 'PerplexityBot', 'Perplexity-User', 'Applebot', 'Applebot-Extended', '*'] as $agent)
User-agent: {{ $agent }}
@endforeach
Allow: /
Disallow: /api/

Sitemap: {{ route('sitemap') }}
