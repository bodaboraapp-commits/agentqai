# AgentQ AI — landing page redesign samples

**Status:** proposal · 19 November 2026 · 5 complete samples + this guide.

The current landing page (`resources/js/pages/welcome.tsx`) is a generic,
theme-driven telemarketing template: dark hero, typewriter headline, six
feature cards, four credit packs, four FAQs. It is well built, but it could
belong to any dialer product — and it does not explain what AgentQ AI actually
is to a buyer, to Google, or to an AI answer engine.

This folder contains five complete, self-contained redesign directions. Every
sample uses the same content architecture and the same SEO / AI-overview
foundation; only the design language changes.

---

## 1. Files

```
design-samples/
├─ index.html                  ← start here: visual comparison of all five
├─ README.md                   ← this file
├─ render-mobile.mjs           ← optional: re-renders the screenshots with headless Chrome
├─ landing/
│  ├─ 01-console.html          ← dark, product-led, live-call console
│  ├─ 02-ledger.html           ← editorial, answer-first (recommended for SEO/GEO)
│  ├─ 03-orbit.html            ← gradient SaaS + interactive credits calculator
│  ├─ 04-handoff.html          ← warm, human, built around AI→human handoff
│  └─ 05-north.html            ← minimal white, enterprise / procurement-safe
└─ screenshots/
   ├─ 00-current-live.png      ← the live site today (before)
   ├─ 01-console-desktop.png   ← plus -mobile.png / -full.png for each sample
   └─ …                         (desktop 1440px, mobile 390px @2x)
```

**No build step and no server are needed.** Open `index.html` or any file in
`landing/` directly in a browser. Each sample is one HTML file with inline CSS
and a few lines of vanilla JS.

---

## 2. How to review

1. Open `index.html` and look at the five previews side by side.
2. Open each sample full-page at ~1440 px wide, then narrow the window to phone
   width. Every sample is responsive.
3. Score them against three questions:
   - Does it feel like AgentQ AI, or like a template?
   - Would a stranger understand the product in 15 seconds?
   - Could an AI assistant quote this page accurately?
4. Pick one direction — or mix (see §4).

Note: sample pages are static HTML for review. The chosen direction gets ported
into the real Laravel + Inertia + React stack (see §9).

---

## 3. The five directions

| # | Direction | One-line idea | Best for |
|---|-----------|---------------|----------|
| 01 | **Console** | The hero *is* a live AI call: transcript, latency, callback booked, CRM updated. | Technical buyers, ops, developers |
| 02 | **Ledger** | A product overview written like a publication: quick answer, key facts, comparison, glossary, FAQ. | SEO + AI Overviews, considered buyers |
| 03 | **Orbit** | Modern AI-SaaS gradient aesthetic, dashboard preview, use-case tabs, credits calculator. | Paid traffic, demos, fast conversion |
| 04 | **Handoff** | The story is a conversation: AI answers, caller asks for a human, warm transfer with context. | SMBs, clinics, services, non-technical buyers |
| 05 | **North** | White, hairline rules, oversized type, numbered sections, pricing table. | Enterprise, regulated, procurement |

### What is intentionally different

- **01 Console** leads with product proof and uses a blunt comparison table.
  It is the most "we are a real tool" direction.
- **02 Ledger** is the most extractable. It reads as a reference page, which is
  exactly what AI Overviews, Perplexity and ChatGPT like to quote.
- **03 Orbit** is the most conversion-oriented: calculator, tabs, familiar SaaS
  patterns, lowest explanation cost.
- **04 Handoff** removes the fear of AI: the handoff moment is the headline.
- **05 North** is the credibility play: no gradients, no gimmicks, very fast.

### Recommendation

- **If you want one:** **02 Ledger** — the content spine is the competitive
  advantage for search and AI answers.
- **If conversion is the KPI:** **03 Orbit**.
- **If enterprise/procurement is the KPI:** **05 North**.
- **Best hybrid:** Ledger's content spine + Console's live-call product moment +
  Orbit's calculator. I can build that as a sixth, combined page if you prefer.

---

## 4. Shared content architecture

Every sample follows the same spine, in roughly this order:

1. **Hero** — one H1 with the primary keyword, a concrete promise, two CTAs and
   three trust chips.
2. **Answer-first block** — a visible 40–60 word definition of AgentQ AI near
   the top ("What is AgentQ AI?"). This is the paragraph an AI is most likely
   to quote.
3. **Proof strip** — product properties (40+ languages, credits never expire,
   human handoff), not invented customer counts.
4. **Product** — 3–6 capabilities: AI voice agents, campaigns, SMS, sequences,
   analytics, integrations/knowledge base.
5. **How it works** — three steps, under an hour to first call.
6. **Comparison** — AgentQ AI vs a dialer with an AI add-on vs building it
   in-house. Answers the "why not just use X?" query directly.
7. **Pricing** — four credit packs, per-call rate, "what counts as a call",
   failed calls free, credits never expire.
8. **Security** — GDPR/TCPA-aligned flows, 2FA, RBAC, KYC, encryption (worded
   honestly: "designed to support", not "certified").
9. **FAQ** — 8–10 questions answered in 2–4 sentences, marked up as FAQPage.
10. **Final CTA + footer** — pricing CTA, docs, llms.txt, sitemap, legal pages.

The samples keep the real product facts from `README.md` and the current theme
data: 40+ voice languages, 10-language UI, Twilio/Plivo/Africa's Talking, SIP /
BYOC, Deepgram + OpenRouter + OpenAI, 2-way SMS, sequences, KYC, credits.

---

## 5. SEO / AI-overview (GEO) audit

### What the current site already does well

Credit where due — `app/Support/Seo.php`, `app/Http/Controllers/SeoController.php`
and `resources/views/app.blade.php` are genuinely good:

- Server-rendered `<title>`, description, canonical, robots, OG/Twitter tags.
- A schema.org `@graph` with Organization, WebSite, WebPage,
  SoftwareApplication and FAQPage.
- A `<noscript>` fallback containing readable product and FAQ copy for
  crawlers that do not execute JavaScript.
- `/robots.txt` that explicitly welcomes GPTBot, OAI-SearchBot, ClaudeBot,
  PerplexityBot, Google-Extended and others.
- `/sitemap.xml` generated per installation.
- `/llms.txt` with a summary, pages, capabilities, pricing and FAQ.
- Compression, cache headers and immutable asset caching in `.htaccess`.

This is better SEO plumbing than most SaaS products ship with. The redesign
keeps it and extends it.

### Gaps the redesign closes

| Gap | Impact | Fixed by |
|-----|--------|----------|
| `theme_settings.meta_title`, `meta_description`, `meta_keywords` are **NULL** in the database | Title falls back to "AgentQ AI — Voice Calling Reimagined"; description becomes one thin tagline | Sample meta + seed real values (§7) |
| Hero copy is generic ("Voice Calling Reimagined") and every feature card is template copy | Nothing unique to rank for; nothing exact to quote | New H1 + answer-first definition in every sample |
| No definition, key-facts table, comparison, glossary or "what is" content | AI Overviews prefer explicit, extractable answers | Added in every sample; glossary in Ledger |
| Only 4 FAQs, none about AI agents | Misses the actual query space | 8–10 FAQs per sample |
| Feature DB rows omit AI voice agents, knowledge base, handoff, SMS, sequences | Page undersells the product and the keywords | New feature set in §7 |
| Stats are unverified (`10M+ calls`, `10,000+ businesses`, `99.9% SLA`, `<100ms`) | AI engines and buyers punish unsupported claims | Replaced with product facts; verify before re-adding (§8) |
| No OG image (`og_image_path` is NULL) | Weak social/answer-engine previews | Add `public/og-image.png` (1200×630) |
| Typewriter headline + three 3D canvas backgrounds on the landing page | LCP/CLS/TBT risk on mid-range phones | Samples use CSS-only motion, no external requests |
| Brand colour `#12ab63` exists but the page is monochrome | Brand inconsistency | New samples use it as a deliberate accent |
| `public/logo.svg` is the Laravel framework mark plus the AgentQ AI wordmark (including Laravel red `#FF2D20`) | Brand credibility problem in the repo/bundled asset | Design a real AgentQ mark; the samples use a placeholder phone/agent glyph |

### Technical choices in the samples (all worth keeping)

- One `<h1>`, logical `<h2>`/`<h3>` hierarchy, semantic landmarks
  (`header`/`nav`/`main`/`section`/`footer`), skip link, visible focus states.
- No images, fonts or scripts from third parties. System font stacks, inline
  CSS, small vanilla JS → fast LCP, zero CLS, no render-blocking requests.
- `prefers-reduced-motion` respected; FAQ uses native `<details>` (keyboard and
  no-JS friendly).
- JSON-LD `@graph`: Organization, WebSite, WebPage, SoftwareApplication
  (with `offers`), HowTo, FAQPage, BreadcrumbList — plus `DefinedTermSet`
  in Ledger.
- `<link rel="alternate" type="text/plain" href="/llms.txt">`.
- Visible "Last updated" date and, in Ledger, a "Cite this page" line.
- Comparison tables use real `<table>`, `<caption>`, `<th scope>`.

### What cannot be fixed by a redesign alone

- Search rankings need backlinks, content depth (docs, blog, case studies) and
  time. The landing page is the conversion + entity page, not the whole SEO
  programme.
- AI Overviews favour pages that are cited elsewhere. Publishing the docs,
  pricing and comparison content as indexable pages helps more than any hero
  animation.
- Review stars / ratings in rich results are deliberately **absent** from the
  samples. Do not add `aggregateRating` unless you have real, verifiable
  reviews — fake review markup is a manual-action risk.

---

## 6. Canonical copy (use this as the source of truth)

**Definition (52 words)**

> AgentQ AI is a cloud platform for AI voice agents, automated calling campaigns
> and two-way SMS. Its agents hold real conversations in 40+ languages, qualify
> leads, book appointments and transfer to people — billed pay-as-you-go with
> credits that never expire.

**H1 options**

- AI phone agents that sound human — and never clock out. *(Console)*
- AI voice agents that answer, qualify and book — in 40+ languages. *(Ledger)*
- Put an AI agent on every call. *(Orbit)*
- Answer the call. Keep the human. *(Handoff)*
- AI voice agents for every call you cannot miss. *(North)*

**Key facts**

| Field | Value |
|-------|-------|
| Category | AI voice agent / automated calling platform |
| Channels | Inbound & outbound voice, two-way SMS, follow-up sequences |
| Languages | 40+ voice languages; 10-language account UI |
| Telephony | Twilio, Plivo, Africa's Talking, SIP / BYOC |
| Billing | Prepaid credits; 100 calls from $10; failed calls free |
| Expiry | Credits never expire |
| Handoff | Rules-based live transfer with transcript and context |
| Analytics | Live transcripts, outcomes, sentiment, per-call cost |
| Integrations | CRM webhooks, REST API + OpenAPI, Stripe / Razorpay |
| Setup | First call in under an hour |
| Security | 2FA, RBAC, KYC, encryption in transit and at rest |

**Pricing (already in the database)**

| Pack | Price | Per call | Note |
|------|-------|----------|------|
| 100 credits | $10 | $0.10 | Starter |
| 500 credits | $45 | $0.09 | Most popular, 10% off |
| 1,000 credits | $80 | $0.08 | 20% off |
| 5,000+ credits | Custom | Best rate | Volume |

One credit = one **connected** call. Failed, busy or unanswered calls are free.

---

## 7. Claims to verify before launch

These are on the current site or in the theme data. Keep only what you can
defend — for both buyer trust and AI-engine trust.

| Claim | Where | Recommendation |
|-------|-------|----------------|
| 10M+ calls delivered | `theme_stats` | Replace with a product fact, or show a dated, real measurement. |
| 99.9% uptime SLA | `theme_stats` | Say "99.9% uptime target" unless the SLA is contractual. |
| 10,000+ businesses | `theme_footer` | Remove or replace with real customer count. |
| `<100ms` latency | `theme_stats` | The product doc says sub-second replies. Use "under 1 second" or a measured figure. |
| 40+ languages | `theme_faqs`, features | Consistent with the product docs — keep. |
| GDPR / TCPA compliance | Feature copy | Keep as "designed to support GDPR and TCPA-aligned calling" (as in the samples). |
| "Enterprise security / bank-level encryption" | Feature copy | Keep only if you can describe the actual controls. |

Swapping in the samples' product facts means the page is true on day one and
can add customer proof once it exists.

---

## 8. Database changes the chosen design needs

The current page is fully DB-driven (`theme_settings`, `theme_hero`,
`theme_stats`, `theme_benefits`, `theme_features`, `theme_use_cases`,
`theme_pricing`, `theme_faqs`, `theme_footer`).

Two options for the redesign:

**Option A — keep it DB-driven (recommended for the template product)**
- Update `theme_features` to the new 6–10 features (AI voice agents, campaigns,
  SMS, sequences, analytics, knowledge base, integrations, teams).
- Update `theme_faqs` to 8–10 questions (see §6 / any sample).
- Update `theme_hero` copy and `theme_settings.meta_*` values.
- Add small tables for the new sections (`how_it_works`, `glossary`,
  `comparison_rows`) if admins should edit them. Otherwise keep those sections
  static in the page component.

**Option B — hybrid (recommended for AgentQ AI's own site)**
- Keep pricing, features, FAQs and stats DB-driven (they change).
- Make definition, key facts, comparison, glossary and security static in
  `welcome.tsx` (they are brand positioning, not product configuration).

Either way, update `database/seeders/ThemeSeeder.php` so every fresh install
gets the better copy and real meta tags.

---

## 9. Implementation plan (after you choose)

1. **Decide direction** — pick 01–05 or ask for the hybrid (§4).
2. **Brand assets** — new mark/logo, `public/og-image.png` (1200×630), favicon,
   and set `theme_settings.meta_title` / `meta_description` / `meta_keywords`.
3. **Build the page** — replace `resources/js/pages/welcome.tsx`; keep
   `welcome.backup3.tsx`. Reuse the sample's CSS as Tailwind components or port
   the stylesheet. Data-driven sections stay wired to `ThemeService`.
4. **Extend structured data** — add `HowTo`, `DefinedTermSet` and
   `OfferCatalog` to `App\Support\Seo::jsonLdGraph()`. It is rendered
   server-side, so it works even without JavaScript.
5. **Keep `/llms.txt` current** — add the comparison and glossary to
   `SeoController::llms()` so agents get the same facts as humans.
6. **Verify SSR** — `config/inertia.php` has SSR enabled; confirm the SSR
   service is running in production (`php artisan inertia:start-ssr` via
   supervisor) so crawlers get the full HTML, not just the `<noscript>`
   fallback.
7. **QA** — see §10.
8. **Measure** — Search Console (queries/impressions), AI referral traffic
   (chatgpt.com, perplexity.ai, claude.ai referrers), demo-request conversion,
   and scroll depth to the pricing/FAQ blocks.

---

## 10. Acceptance criteria

Before calling the redesign done:

- [ ] Lighthouse mobile: Performance ≥ 90, Accessibility ≥ 95, Best Practices ≥ 95, SEO = 100.
- [ ] Core Web Vitals: LCP < 2.5 s, CLS < 0.1, INP < 200 ms on a mid-range phone.
- [ ] One H1; heading order valid; all interactive elements keyboard reachable.
- [ ] Rich Results Test passes for Organization, SoftwareApplication, FAQPage,
      HowTo and BreadcrumbList.
- [ ] JSON-LD validates at validator.schema.org.
- [ ] Page still explains the product with JavaScript disabled (SSR or fallback).
- [ ] Meta title 50–60 chars, description 140–160 chars, canonical correct,
      OG image renders on X/LinkedIn/WhatsApp.
- [ ] All pricing and claims match the actual product and billing rules.
- [ ] Every internal link resolves (`/documentation`, `/page/privacy-policy`,
      `/page/terms-and-conditions`, `/register`, `/login`).
- [ ] Payments/registration CTAs work end to end on staging.

---

## 11. Decisions I need from you

1. **Which direction?** (Or the hybrid: Ledger spine + Console product moment +
   Orbit calculator.)
2. **Market focus** — global, Kenya/Africa-first, or both? One sample could be
   re-tuned for M-Pesa and local telephony (Africa's Talking is already
   supported).
3. **Brand** — keep `#12ab63` as the accent? And do you have a real logo, or
   should I design a simple AgentQ mark?
4. **Claims** — can I replace the unverified stats with product facts, or do you
   have real numbers to use?
5. **Copy tone** — technical (Console), authoritative (Ledger), bold (Orbit),
   warm (Handoff) or reserved (North)?

Reply with a number (or a mix) and I will implement it in
`resources/js/pages/welcome.tsx`, wire the SEO structured data, update the
seeders, and run the QA checklist.
