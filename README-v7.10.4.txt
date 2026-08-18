Tuningland AI Vehicle Manager 7.10.4

Fixes:
- Gemini Worker batch is truly primary; legacy web-first pipeline is not silently used when Gemini was attempted.
- Gemini 2.x saved model settings migrate to gemini-3.6-flash.
- Internal c-cat image/banner fields are recognized even when ACF field type is URL/text.
- ACF image fields can resolve existing attachment IDs from data-bg URLs.
- Internal vehicle-image fields are included in researchable fields.
- Gemini batch prompt now explicitly ranks the relevant page/section per field and prevents cross-field contamination.

Worker:
Deploy docs/cloudflare-gemini-worker.v3.js to the same Cloudflare Worker and keep GEMINI_API_KEY as a Secret.
