Tuningland AI Vehicle Manager 7.10.0

Gemini priority:
1. Gemini via Cloudflare Worker (recommended; keep Gemini secret on Worker)
2. Gemini direct (optional)
3. DeepSeek (optional)
4. OpenAI (optional)
5. Existing web research/search pipeline remains the fallback source layer.

Vehicle images:
The ACF group named "تصاویر خودرو" is automatically treated as an internal asset group.
For image/gallery fields the plugin first searches WooCommerce product_cat terms under c-cat,
matching the vehicle title. It then reads ACF term fields and WooCommerce thumbnail_id before
falling back to the internal Media Library. No external web search is used for these image fields.

Cloudflare Worker:
Use docs/cloudflare-gemini-worker.example.js as a starting point. Add GEMINI_API_KEY as a
Cloudflare Worker Secret. Put the Worker URL in AI Settings. The plugin sends JSON with model,
input, instructions, temperature and max_output_tokens.
