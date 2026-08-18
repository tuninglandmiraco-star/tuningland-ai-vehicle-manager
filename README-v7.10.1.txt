Tuningland AI Vehicle Manager v7.10.2

Changes:
- Independent enable/disable switch for each AI provider.
- Gemini Cloudflare Worker can be enabled independently and remains the first default provider.
- AI-first research: the runner asks an enabled AI provider before starting web search. Web research is fallback only when AI fails/returns no reliable value.
- Provider test now reports success/failure, HTTP status when available, and the returned text in the settings notice.
- Existing c-cat/internal vehicle image resolver remains intact.

Recommended provider setup:
1. Enable Gemini via Cloudflare Worker.
2. Put your Worker URL in AI Settings.
3. Disable Gemini Direct, DeepSeek and OpenAI unless you actually want them as fallbacks.
4. Save settings, then click Test Gemini via Cloudflare Worker.
5. A successful test should show the returned text (normally OK).

Important:
The AI-first path only falls back to web when the provider is unavailable or returns no value. For high-risk factual fields, the AI prompt explicitly asks the model not to guess and to return null when it cannot verify the value.
