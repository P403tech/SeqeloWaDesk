# Checkpoint — Shop Manager local demo

Saved so we can test this again later.

## When we stopped (3 Sep 2026)

- Shop self-service flow (1–10 menu + View menu) is on `main` (`7d0b7b6`). After deploy: `php artisan db:seed --class=Database\Seeders\FlowTemplateSeeder`
- Local demo: `demo/shop-manager-chat/` — text + voice + sub-agent routing
- **WaDesk router (in progress):** `App\Services\Ai\ShopManagerRouter` runs on AI replies when **Shop Manager router** is on (Team Inbox agent form) or the system prompt contains `[shop-manager]`. Migrate: `php artisan migrate`. Voice notes use the same `generateReply` path.

## How to open the demo

```bash
cd "C:\Users\My pc\Downloads\Seqelo project\WaDesk\WaDesk\demo\shop-manager-chat"
python -m http.server 8765
```

Open http://127.0.0.1:8765/

Try: `hi`, `WD-1042`, `red A24 cover`, messy questions, mic. Leave **Live AI** off unless you paste an OpenAI key.

## Next

1. Test the demo together (and Shop Manager toggle on a real agent after migrate)
2. Optionally start the matching shop-flow branch from a list tap, not only specialist prompts
