# Paid Telegram access

A small PHP reference for the part nobody writes up: **how a customer
gets into a private Telegram group after paying, and how they get out
when the subscription lapses** — in a way that still lets them come back.

## Why an invite link is not enough

Because a link can be forwarded. One shared, reusable invite opens the
whole group with a single screenshot, and afterwards there is no way to
tell who joined legitimately.

The other half of the problem is expiry. Telegram has no idea that
someone stopped paying. Your own system has to keep track of that, and
your own system has to act on it.

## What is worth taking from this

The amount of code is not the point. Three decisions are:

**1 · The invite is single-use.**
`member_limit = 1` on `createChatInviteLink` is what makes it one: the
link is spent after a single join, so there is nothing to pass on. It
gets an expiry too, because forgotten links that live forever accumulate.

**2 · Something has to connect the payment to the Telegram account.**
This is the least obvious part of the system. At the payment provider
the customer has an email address; in Telegram they have a numeric ID,
and nothing links the two on its own. The answer is a pairing code: the
customer receives a `https://t.me/BOTNAME?start=CODE` link, and when the
bot starts it sees the code **and** the user's ID at the same moment. The
code is single-use, and it expires.

**3 · Removal is two calls, not one.**
`banChatMember` on its own means "leave, and don't come back" — a banned
user cannot rejoin, not even with a fresh invite. It is a default nobody
notices, and it closes the door on every returning customer. It has to be
followed by `unbanChatMember`, with `only_if_banned = true`: without that
flag, calling it on someone who is currently a **member** removes them.

Two smaller things that only surface in production:

- **The Bot API does not hand out the full member list.** It will tell
  you whether one specific person is a member (`getChatMember`), but
  there is no call for "who is in right now?". That is why keeping your
  own record is not a convenience extra — it is the backbone.
- **Removing at the minute of expiry is too early.** Payment providers do
  not call a failed renewal on the first try; they retry the charge for
  days. Whoever gets removed immediately gets removed over an expired
  card. Hence the grace period (`graceDays`).

## Layout

```
src/TelegramClient.php   the four Bot API calls this needs
src/AccessManager.php    the lifecycle: pairing → join → expiry → removal
src/MemberStorage.php    the storage seam
src/MemberStore.php      a MySQL implementation of it
public/bot.php           the bot webhook: /start arrives here with the code
bin/expire.php           the daily cron that removes expired members
```

## Usage

```bash
cp config.example.php config.php    # fill it in
mysql < schema.sql
```

Add the bot to the group as an **admin**, with "Ban users" and "Invite
users via link" permissions — nothing works without both.

Register the webhook together with its secret:

```bash
curl -F "url=https://example.com/bot.php" \
     -F "secret_token=SAME_AS_IN_CONFIG" \
     "https://api.telegram.org/bot<TOKEN>/setWebhook"
```

The payment side only has to call `createPairingCode()` after a
successful payment and build the link from what comes back:
`https://t.me/BOTNAME?start=CODE`. Send it by email or show it on the
thank-you page.

It runs without a network or a database:

```bash
php test.php     # plays through the whole lifecycle in memory
```

## What this does not cover

- **The payment.** That is the other half of the job, and it lives in
  [stripe-szamlazz-hu](https://github.com/kristofkarner/stripe-szamlazz-hu).
  This code starts from the moment something says "this customer is
  entitled until this date".
- **Notifications.** Whether members get a warning before expiry, and how
  early, is a business decision. `removeExpired()` is where it hooks in.
- **Channel versus group differences.** The calls are the same, but
  permissions and visibility are not — try it on the type you will
  actually use.

## Related writing

The reasoning at length, with the traps:

- [Paid Telegram access](https://kristofkarner.com/en/writing/paid-telegram-access/)
- [Telegram Stars or a custom system?](https://kristofkarner.com/en/writing/telegram-stars-or-custom-system/)

> *Magyarul is megvan mind a kettő:*
> [Fizetős Telegram-hozzáférés kezelése](https://kristofkarner.com/irasok/fizetos-telegram-hozzaferes-kezelese/) ·
> [Telegram Stars vagy saját rendszer?](https://kristofkarner.com/irasok/telegram-stars-vagy-sajat-rendszer/)

## Licence

MIT — use it freely. A star is appreciated if it helped.

---

Built by [Kristóf Karner](https://kristofkarner.com) · independent web
developer, Budapest
