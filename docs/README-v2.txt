Tuningland Gemini Worker v2
===========================

1) In Cloudflare Workers > Settings > Variables and Secrets, create a SECRET:
   GEMINI_API_KEY = your Gemini API key

2) Optional variable:
   GEMINI_MODEL = gemini-2.5-flash

3) Replace the Worker code with:
   cloudflare-gemini-worker.v2.js

4) Deploy the Worker.

5) In Tuningland > AI Settings enter the Worker URL, for example:
   https://YOUR-WORKER.workers.dev/

6) Enable ONLY:
   Gemini via Cloudflare Worker

7) Save settings.

8) Click Test Gemini via Cloudflare Worker.
   The plugin now uses AJAX and shows connection status without reloading.

9) Research flow:
   Tuningland -> Gemini Worker -> Gemini -> Google Search grounding when needed ->
   structured JSON/prompt-validated result -> Tuningland validation -> Web Research fallback.

IMPORTANT:
- The Worker keeps the Gemini API key server-side.
- Gemini 2.5 Flash can use Google Search grounding, but strict structured-output +
  built-in tool combinations are documented by Google for Gemini 3 series. The Worker
  therefore requests JSON in the prompt and has a compatibility retry when a model
  rejects responseMimeType together with tools.
- For the strongest structured-output + Search combination, use a supported Gemini 3
  model if it is available to your API project and set GEMINI_MODEL accordingly.
