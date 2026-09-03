# Checkpoint — Shop Manager local demo

Saved so we can test this again later. This is a browser demo, not live WhatsApp.

## When we stopped (3 Sep 2026)

- Shop self-service flow (1–10 menu + View menu) is on `main` (`7d0b7b6`). After deploy: `php artisan db:seed --class=Database\Seeders\FlowTemplateSeeder`
- Agreed design: one Shop Manager on the outside; catalog / orders / COD / returns as sub-jobs; voice and text share the same brain
- Demo files: `index.html`, `app.js`, `shop-brain.js`, `styles.css`
- **Not done:** test this demo together; wire the manager/sub-agent router into real WaDesk

## How to open the demo

```bash
cd "C:\Users\My pc\Downloads\Seqelo project\WaDesk\WaDesk\demo\shop-manager-chat"
python -m http.server 8765
```

Open http://127.0.0.1:8765/

Try: `hi`, `WD-1042`, `red A24 cover`, messy questions, mic. Leave **Live AI** off unless you paste an OpenAI key in the browser.

## Next

1. Test the demo together
2. Then build the real Shop Manager router inside WaDesk
