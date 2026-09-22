# 📚 MutolaachiBot

Telegram orqali PDF va audio kitoblar bilan ishlaydigan PHP webhook bot.

## Stack
- PHP 8.1+
- Telegram Bot API
- cURL
- JSON storage

## Sozlash

Environment variables:

    BOT_TOKEN=123456:ABC...
    ADMIN_IDS=123456789
    BOT_USERNAME=MutolaachiBot
    WEBHOOK_SECRET=

Webhook:

    https://api.telegram.org/bot<TOKEN>/setWebhook?url=https://YOUR-DOMAIN/bot.php

## Asosiy komandalar

- `/start` — boshlash
- `/addbook` — admin uchun kitob qo‘shish
- `/books` — kitoblar
- `/users` — foydalanuvchilar soni
- `/stats` — statistika
- `/cancel` — amalni bekor qilish

## Loyiha tuzilishi

- `bot.php` — asosiy bot
- `data/` — JSON ma'lumotlar
- `uploads/pdf/` — PDF fayllar
- `uploads/audio/` — audio fayllar
- `uploads/covers/` — muqovalar

⚠️ Bot tokenini GitHub'ga joylamang. Environment variable ishlating.
