# Changelog

Release notes for AgentQ AI, newest first. This file is written for the person who
owns the installation — what changes for you, and what you need to do about it. It
follows the [Keep a Changelog](https://keepachangelog.com/en/1.1.0/) format and the
project uses [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

This file starts at 7.1.0. Notes for earlier releases are in the item's changelog on
CodeCanyon.

## [7.2.0] - 2026-11-04

This release closes several ways a customer could get credit without paying for it,
read another customer's data, or take over another customer's phone line. It also
starts billing two things that were never billed. **Read the next section before you
upgrade, and do the steps under *After you upgrade* the same day.**

It also lets a campaign show and choose its AI agent, lets a stuck telephony reset
finish, and fixes Plivo setup for sites whose name has a space in it.

### Read this first — what changes your bill

- **Twilio SMS are charged, for the first time.** An SMS is charged when its delivery
  receipt arrives, and messages sent through Twilio never asked for a receipt — so on a
  Twilio installation no SMS was ever charged, AI replies included. From the upgrade,
  every SMS is charged at your SMS rate, per segment. Check your SMS prices before you
  upgrade. Plivo installations were already charged and see no change.
- **Monthly number rental is charged, for the first time.** The monthly charge stopped
  with an error on any installation that had an assigned number, so after the month a
  number was bought nobody was ever charged for it again. From the next 1st of the month,
  each customer is charged each number's monthly cost, as the product always intended —
  and a customer who cannot pay loses the number. **Tell your customers before the 1st.**

### Security

- **A top-up is credited only when the payment gateway says it was paid, in full.**
  With Razorpay, one small payment could complete any number of other pending top-ups,
  of any size. With PayPal, a payment for one top-up could complete a larger one. And a
  payment notification sent to your site was accepted without being verified whenever
  its gateway's webhook secret was not set. All of these are closed. **Payment
  notifications are now refused unless their secret is set** — see step 4 below.
- **KYC identity documents are visible to admins only.** Any signed-in user could
  download another user's passport scan, selfie or business registration, and mark
  documents as approved.
- **Each customer's AI-agent data is their own.** Any signed-in user could list, read and
  export every customer's AI-agent calls and transcripts, read, change or delete every
  customer's knowledge bases, and move another customer's number onto their own agent —
  taking over that customer's inbound line.
- **"Import from URL" fetches public web pages only.** It fetched any address it was
  given, including your server's own network and your cloud provider's metadata address,
  which holds the server's credentials.
- **Password-reset links can no longer be pointed at another site.** A forgot-password
  request for someone else's email address could make the link in their email point at a
  site of the requester's choosing, with a working reset code in it. Your installation now
  answers only to the domain in `APP_URL` and its `www.` twin. See step 5.
- **One copy of `.env` is kept instead of thirty.** The daily copy in
  `storage/backups/env/` stays, because it is how an emptied `.env` is restored with its
  encryption key, but only the newest is kept, an older copy made under a different key is
  cut down to that key alone, and `ENV_BACKUPS=false` keeps none at all.

### Added

- **A campaign shows, and lets you choose, the AI agent that takes its calls.** A
  campaign used to get whichever agent answered on the number it calls from, without the
  screen saying so, and the match could fail over nothing more than a missing `+`,
  leaving the campaign without its agent. The agent is now filled in from the number and
  can be changed. Numbers are compared by their digits, so `+15551234567` and
  `+1 555 123 4567` are the same number; one saved without its country code is still not
  matched, and the screen now says so.
- **"Reset telephony" can end the work that blocks it.** A reset is refused while a call
  is in progress or a campaign is dialling, and a call whose last status update never
  arrived could block it for good. A new checkbox beside the confirmation (or
  `--end-work-in-flight` on `php artisan telephony:reset`) hangs up the calls still in
  progress, pauses running campaigns and returns scheduled ones to draft, then resets.
  The reset itself is exactly as destructive as before.

### Fixed

- **Razorpay webhooks credit the top-up they paid for.** They never found it before, so
  a buyer who closed the tab after paying was not credited.
- **A top-up in another currency is refused when the exchange rate cannot be fetched**,
  instead of crediting the unconverted figure — 5,000 rupees became 5,000 dollars of
  credit.
- **The AI-agent calls CSV export works.** It always failed with an error.
- **Plivo setup works when your site's name has a space in it.** Verifying Plivo
  credentials failed with "Invalid Application name" whenever `APP_NAME` contained a
  space, so inbound calls and SMS could not be set up. If that happened to you, click
  **Verify** again under **Admin → Telephony Settings** after upgrading. An installation
  that already verified is not affected.
- **An AI-agent campaign can be launched without a message or an audio file.** The agent
  does the talking, but launching still demanded a script that would never be played.
  The campaign form now says that the agent speaks instead.
- **Applying a campaign template always selects a campaign type.** A template saved
  without one left both options unselected, and the campaign would not save until you
  picked one.

### Changed

- **A customer can give an AI agent only a phone number they own**, and the number list
  on the agent page shows only theirs (an admin still sees every number). An agent that
  already has a number keeps it.
- **Knowledge bases belong to their creator.** On upgrade, each existing one is given to
  the owner of an AI agent that uses it. One that no agent uses has no owner to give it
  to, so only admins see it; if a customer misses one, that is where it is.
- **System Configuration** has a field for the Stripe webhook signing secret, and the
  webhook secret fields for Razorpay, PayPal and Flutterwave are no longer marked
  optional.
- **The download unpacks straight into the folder you extract it in** — `app/`,
  `public/`, `vendor/` and the rest, with no folder around them. See *Upgrading*.

### Upgrading

Back up your files and your database first. Then extract the download in your
installation's main folder (on cPanel, usually `public_html`) and let it replace the
files that are there. It contains no `.env` and none of your uploads, logs or data, so
your settings and everything your customers have created stay as they are. Then do the
steps below.

### After you upgrade

1. **Run the database migrations** — `php artisan migrate`. This adds the owner column to
   knowledge bases and gives existing ones their owners, and loads any interface wording
   your installation is missing.
2. **Clear your cached configuration** — `php artisan config:clear`.
3. **Restart your queue workers** — `php artisan queue:restart` (or `pm2 restart all`).
4. **Set the webhook secret for every payment gateway you use**, under **Admin → System
   Configuration**: the Stripe webhook signing secret, the Razorpay webhook secret, the
   PayPal webhook ID or the Flutterwave secret hash. Until you do, that gateway's payment
   notifications are refused, and a top-up is credited only when the buyer comes back to
   your site after paying.
5. **Check that `APP_URL` in `.env` is the address your customers use.** If your site
   also answers on another domain, or a monitor checks it by IP address, list those in
   `APP_TRUSTED_HOSTS`, separated by commas — a request for any other host is now refused.
   If `APP_URL` is still `http://localhost`, set it: nothing is refused until you do, but a
   warning is logged every day.
6. **Review your SMS prices, and tell your customers about number rental** — see *Read
   this first*.

## [7.1.0] - 2026-09-18

AgentQ AI now runs on **Twilio or Plivo**. You choose one when you install, and the
product does the same jobs on either: campaigns, the browser softphone, inbound calls,
call recording, keypad menus, two-way SMS, AI voice agents, buying numbers, KYC phone
verification and the inbound call queue. Existing installations stay on Twilio
automatically and nothing you have set up moves.

**If you are choosing Plivo, two differences in keypad campaigns are worth knowing**,
because the choice is permanent. In a campaign that asks the caller to press a key, the
script plays *inside* the question on Plivo, so a caller can cut it short by pressing a
key early; on Twilio the script always finishes first. And a Plivo caller who presses
nothing hears a short line in English — "We didn't receive any input. Goodbye." — before
the call ends, whatever language the campaign is in. Campaigns that do not ask for a
keypress sound the same on both. Some Twilio-only features are also hidden on Plivo;
they are listed under **Changed** below.

This release also repairs several things that had been failing quietly. **Five of those
repairs change what you are billed, or change numbers you look at, from the day you
upgrade.** Read the next section before you upgrade rather than after.

### Read this first — what changes your bill and your numbers

- **Campaign calls to an AI voice agent work at all, for the first time since December
  2025.** On every installation those calls were dying after about 8 seconds and the
  person who answered heard an error message. They now run as real conversations.
  Calls are charged by how long they last, so the credits taken for one of these calls
  rise roughly **thirtyfold** — an 8-second failure becoming a four-minute conversation.
  Your own telephony minutes and your OpenRouter (AI model) spend rise by the same
  amount. Nothing in how credits are calculated changed; the calls are simply real now.
  **If you resell credits, review what you charge for AI-agent campaigns before you
  upgrade.**
- **Long SMS messages are billed correctly.** A message the carrier splits into three
  parts used to be charged as one. It is now charged as three. That is a real increase
  in what your SMS traffic costs, and in what it costs your customers, from the day you
  upgrade.
- **AI-agent profit figures fall to the truth.** AI-agent call records never closed, so
  the length and the carrier cost of those calls were never written down and the profit
  dashboard showed a better margin than you were actually making. Those records close
  now. The margin you see will drop. It was never as high as the old figure.
- **Conversation turn counts show about a third of their old value.** One exchange with
  an AI agent was counted three times, both on screen and in CSV exports. Calls from
  before the upgrade keep their inflated figure, so there is a visible step down in the
  numbers at the point you upgrade. Same conversations, counted correctly.
- **An inbound call queue owned by one of your customers now connects callers to
  agents.** On Twilio, a queue belonging to anyone other than an admin failed silently:
  the caller waited on hold while the agent was told there was nobody in the queue.
  It works now — so those calls connect, and they are charged like any other call.
- **Nothing you have scheduled needs editing.** `twilio:fetch-pricing` still works
  exactly as it did. Leave your cron jobs and your scheduler alone.

### Added

- **Pick your telephony provider at install time.** A new step in the installation
  wizard asks for Twilio or Plivo, with Twilio pre-selected. The choice is permanent for
  that installation — see *After you upgrade*.
- **Telephony page in the admin panel.** One place to see which provider the
  installation uses, enter your account credentials and check them against the provider
  before you save.
- **Plivo across the whole product** — outbound campaign calls with text-to-speech or
  pre-recorded audio, inbound call routing, the browser softphone, call recordings,
  keypad (IVR) input, two-way SMS, AI voice agents, and buying and releasing numbers.
- **KYC phone verification on Plivo.** A six-digit code sent as an ordinary SMS from
  your own number, valid for ten minutes, with a hard limit of five attempts that
  simultaneous guesses cannot get past. The code is never stored in readable form.
- **Country pricing for Plivo.** `pricing:fetch` imports Plivo's published rates for
  every country into your pricing rules, keeps everything it fetched even if the
  provider stops answering part way, and lists in the log every country it could not
  price so you can set those rates yourself. **Set them.** Until a country has a rate of
  its own, calls and messages to it are charged at your **US** rate — which is less than
  your carrier charges you for every destination dearer than the US, so you sell those
  below cost until you notice.
- **The inbound call queue works on Plivo.** Each waiting caller gets their own room and
  the agent joins it. If the agent's phone does not answer, the caller returns to the
  queue for the next agent instead of being dropped.
- **Webhook signature checking for Plivo**, the same protection Twilio calls already
  had, so nothing can fake a call or message notification to your installation.

### Changed

- **Cost lines no longer name your carrier.** An AI-agent cost entry that read
  "Twilio: …" now reads "Telephony: …", so nothing in the product tells your customers
  which company carries your calls.
- **`twilio:fetch-pricing` is now another name for `pricing:fetch`** — same command,
  same options, same output, and it fetches from whichever provider your installation
  uses. Existing cron entries and the scheduler need no edit.
- **On a Plivo installation, features only Twilio offers are hidden instead of shown
  broken**: SIP trunking, BYOC (bring your own carrier), Twilio account migration,
  geographic call permissions, the softphone's hold button, placing an outbound
  AI-agent call from the agent page, and every answering-machine statistic — the
  campaign "Answered" count, the campaign list's answer rate and the analytics
  dashboard's answered figure — all of which count only calls confirmed by
  answering-machine detection, something this product does not switch on for Plivo.
  **Twilio installations lose nothing; none of this is hidden there.**
- **REST API:** a campaign's type is now reported as `tts` or `audio`, which is what the
  API documentation has always promised. It previously came back as the internal values
  `text_to_speech` and `voice_to_voice`. The whole API speaks the documented words now —
  campaign responses, the campaign embedded in a call response, and filtering a campaign
  list by type, which previously accepted only the internal values and matched them as a
  substring, so the documented `tts` returned an empty list. The filter now takes exactly
  `tts` or `audio` — a comma-separated list of the two is accepted — which also means a
  partial word such as `speech` no longer matches anything. The old internal values still
  work. Sequence success and conversion rates come back as numbers now (`12.5`) rather
  than quoted strings (`"12.50"`), and so do message-variant answer rates, completion
  rates and effectiveness scores on `GET /api/v1/campaigns/{id}/variants` (`33.33`, not
  `"33.33"`). Update any integration that reads or writes the old values.

### Fixed

- **Upgrading no longer leaves screens showing raw key names.** The interface reads its
  wording from your database, and that wording was only ever loaded when a site was
  first installed — so anything added in a later version reached upgraded sites as the
  key instead of the words, for example `admin.telephony.locked_to` where **Locked to**
  belongs. Running the migrations in step 1 above loads everything your version is
  missing, in every language. Wording you rewrote yourself under **Languages** is kept
  exactly as you wrote it. If a screen still shows key names afterwards, run
  `php artisan translations:seed-keys` and then `php artisan cache:clear`.
- **The embeddable softphone loads its stylesheet.** The snippet under **Settings → API
  Keys** now includes a `<link>` line for it. If you pasted the snippet into a site
  before this release, copy it again from that screen — or add
  `<link rel="stylesheet" href="https://YOUR-DOMAIN/widget/softphone.css">` above the
  script tag yourself — or the widget on your site stays unstyled.
- **Campaign calls and manual calls work on an installation set up the way this product
  documents.** The app looked for telephony credentials attached to the individual
  user and simply gave up when it found none, instead of falling back to the
  credentials you entered in the admin panel.
- **AI voice agents stop forgetting the conversation.** The agent was handed the oldest
  few exchanges, in reverse order, so after about three exchanges it answered as though
  the call had just begun. Its replies will be noticeably different from the fourth
  exchange onwards.
- **An AI agent set to transfer to a human, with no transfer number filled in, no
  longer drops the call with an error.** It speaks its closing line and hangs up.
- **A repeated notification about the same AI-agent call no longer re-runs the AI
  model**, so a provider retry cannot bill you twice for one exchange.
- **A live call is no longer killed while it is still connected.** The sweeper that
  clears stuck calls was marking any call still connected after five minutes as failed
  and dialling that contact again while the first call was still up — the same person
  called twice and charged twice, and counted as both failed and completed. AI-agent
  conversations routinely run longer than five minutes. Calls now wait for the provider
  to report how they ended.
- **Two agents can no longer be handed the same waiting caller.** When two agents take a
  queued call at the same moment, one gets that caller and the other gets the next one.
- **SMS delivery outcomes that used to fail to record are stored**: undelivered,
  accepted, scheduled, sending, read and cancelled. A read receipt deliberately does not
  start a charge.
- **SMS sends that fail are reported as failed** instead of being counted as successes,
  so your delivery figures are honest.
- **Signing out disconnects the browser softphone.** It previously stayed registered
  with the provider, so a signed-out browser could still receive calls.
- **Follow-up sequences work again, in full.** The sequence list and the sequence editor
  returned an error page on every MySQL installation — that is, on every production
  installation — and enrolling a contact failed there with a message that wrongly blamed
  "already enrolled or at capacity". The sequence detail page failed on every
  installation, whatever the database. All of it works.
- **Creating a campaign through the REST API works on MySQL.** It failed on every
  production installation.

### Security

- **Rotate your telephony credentials, and know that your `.env` is being copied every
  day.** This is not something you switched on: every installation copies its `.env`
  file to `storage/backups/env/` on the first visit of each day and keeps the last 30
  days of copies. Every secret your `.env` holds — telephony account ID and auth token,
  payment gateway keys, mail password, application key — sits in those copies in plain
  text. Anyone who can read your `storage/` folder, on shared hosting or in an old
  server backup, can read them. Issue new telephony credentials from your provider's
  console, enter them in the admin panel, and delete the files in
  `storage/backups/env/`.
- **The Twilio client is never built from empty credentials.** Where credentials were
  missing, the telephony library could quietly fall back to whatever `TWILIO_*` values
  happened to exist in the server's environment — on shared hosting, not necessarily
  yours. That path is closed.
- **Plivo webhooks are signature-checked** before the app acts on them.

### Upgrade notes

- **Your installation locks itself to Twilio.** The upgrade records Twilio as your
  provider automatically. Your numbers, campaigns, credentials, call history and SMS
  history are untouched, everything keeps working as it did, and there is no choice for
  you to make.
- **The database gains columns and one wider status list; nothing is removed or
  rewritten.** The upgrade adds columns, a record of which provider you are locked to,
  and one extra value to the list an SMS status may take. No existing row is deleted or
  converted, and no table is rebuilt — which matters if yours is large.
- **Historic figures are not restated.** Turn counts and AI-agent profit figures from
  before the upgrade keep their old values, so expect a visible step in any chart that
  crosses the upgrade date.

### After you upgrade

1. **Run the database migrations** — `php artisan migrate` — before you let anyone use
   the site. The new columns and the provider record are added here. On cPanel this is
   the Terminal tool; if your host does not offer one, ask them to run it for you.
2. **Clear your cached configuration** — `php artisan config:clear`. If you have ever
   run `config:cache`, your installation is holding a copy of the old settings that
   knows nothing about telephony providers, and the app will not start correctly
   without this.
3. **Restart your queue workers, then make sure they and the scheduler keep running.**
   `php artisan queue:restart` (or `pm2 restart all` if you use pm2). A worker that is
   already running holds the old code in memory for up to an hour, so without this,
   old code runs against your new database. After that: campaigns, imports, SMS sending
   and follow-up sequences do nothing unless the workers and the scheduler are running.
   That has not changed, and it is still the most common cause of "the app does
   nothing".
4. **Review your AI-agent campaign pricing.** Those calls now run to their natural
   length, so they cost you and your customers far more per call than they did.
   Do this before you launch the next campaign.
5. **Rotate your telephony credentials and empty `storage/backups/env/`** — see
   **Security** above for why those files exist and what is in them.
6. **If you are installing fresh and choose Plivo, that choice is permanent.** The only
   way out is the "Reset telephony" tool in the admin panel, and it cannot be undone.
   It deletes your provider credentials and settings, your SIP and BYOC trunk setup and
   your call-queue records, and releases every phone number inside the app. Your users,
   credits, contacts, campaigns, call history and message history are kept — though
   campaigns and AI agents keep their settings while losing the number they were
   attached to, and phone number requests still waiting for approval are cancelled. It
   cancels nothing at your provider: those numbers and trunks stay on your Twilio or
   Plivo account and you keep paying for them until you remove them there yourself.
   Choose your provider deliberately.
