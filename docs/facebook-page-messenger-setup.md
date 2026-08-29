# Facebook Page Messenger setup

This module connects a **Facebook Page**, never a personal Facebook account.
It stores the Page access token encrypted and accepts only signed Meta webhooks.

## 1. Create and configure the Meta app

In Meta for Developers, create or select the business app for the SolarNet
Facebook Page. Add the Messenger product and use these exact URLs:

- Valid OAuth redirect URI:
  `https://billing.solarnetportal.com/api/v1/integrations/facebook/callback`
- Webhook callback URL:
  `https://billing.solarnetportal.com/api/v1/integrations/facebook/webhook`
- Webhook verify token: use the value of `FACEBOOK_WEBHOOK_VERIFY_TOKEN`

Subscribe the Page to the `messages` and `messaging_postbacks` webhook fields.
Use a Page administrator account when connecting the Page in SolarNet. Meta may
require App Review before people outside the app's roles can message it.

The SolarNet connection requests `pages_read_engagement` and
`pages_manage_posts` as well as Messenger access. This is used only by the
Administrator-approved **AI Post Studio**;
there is no automatic Page posting. Meta may require the matching permission
and app review before publishing becomes available outside development mode.

AI Post Studio can also attach one PNG/JPEG image to a post or create one
reviewable AI image. The image is stored on SolarNet's private server disk and
is shown only to authenticated administrators until they explicitly publish
the draft. Image generation is an administrator action and uses the existing
server-side OpenAI API project. The default model is `gpt-image-2`; override it
only if your OpenAI project requires a different permitted image model:

```dotenv
OPENAI_IMAGE_MODEL=gpt-image-2
OPENAI_IMAGE_TIMEOUT=120
OPENAI_IMAGE_QUALITY=low
OPENAI_IMAGE_SIZE=1024x1024
```

## 2. Add only server-side settings

Add the following values to `/var/www/solarnet-billing/deploy/.env` on the VPS.
Do not put them in the frontend or Git repository.

```dotenv
FACEBOOK_APP_ID=your_meta_app_id
FACEBOOK_APP_SECRET=your_meta_app_secret
FACEBOOK_WEBHOOK_VERIFY_TOKEN=a-long-random-secret-not-used-anywhere-else
FACEBOOK_GRAPH_API_VERSION=v23.0
FACEBOOK_OAUTH_REDIRECT_URI=https://billing.solarnetportal.com/api/v1/integrations/facebook/callback
```

After editing `.env`, rebuild the backend, worker, and frontend. Then run
`php artisan optimize:clear` inside the backend container.

## 3. Connect and test safely

1. Sign in as Super Administrator or Administrator.
2. Open **Facebook Automation** in the sidebar.
3. Confirm OAuth and Webhook show **Ready**, then choose **Link Facebook Page**.
4. Select only the SolarNet Page.
5. Send a normal test message to that Page from a test account.
6. Confirm the conversation appears in the inbox before enabling AI replies.

Both AI auto-replies and marketing delivery are disabled by default. Marketing
requires staff-recorded Messenger consent, an active 24-hour conversation, and
an explicit **Approve & send now** action. A customer sending `STOP`,
`unsubscribe`, `cancel`, `huwag`, or `ayaw` is opted out automatically.
