/**
 * Tuningland Gemini Worker v3
 *
 * Primary AI gateway for Tuningland.
 * - Gemini 3.6 Flash by default
 * - Real health check (Worker -> Gemini)
 * - Single-field research
 * - 5-field batch research in ONE Gemini call
 * - Google Search grounding handled by Gemini
 * - Stable JSON envelopes for WordPress
 *
 * Cloudflare Secret:
 *   GEMINI_API_KEY
 * Optional variable:
 *   GEMINI_MODEL (default gemini-3.6-flash)
 */

const ALLOWED_ORIGINS = [
  "https://www.tuningland.ir",
  "https://tuningland.ir",
];

const DEFAULT_MODEL = "gemini-3.6-flash";

export default {
  async fetch(request, env) {
    const origin = request.headers.get("Origin") || "";
    if (request.method === "OPTIONS") return corsResponse("", 204, origin);
    if (request.method !== "POST") return jsonResponse({ success:false, error:"POST required" },405,origin);

    try {
      const body = await request.json();
      const action = body.action || "research";

      if (action === "health") return jsonResponse(await healthCheck(env), 200, origin);
      if (action === "research") {
        const result = await research(body, env);
        return jsonResponse(result, result.success ? 200 : 502, origin);
      }
      if (action === "research_batch") {
        const result = await researchBatch(body, env);
        return jsonResponse(result, result.success ? 200 : 502, origin);
      }

      return jsonResponse({success:false,error:"Unknown action"},400,origin);
    } catch (error) {
      return jsonResponse({success:false,error:error?.message || "Worker error"},500,origin);
    }
  }
};

function modelName(body, env) {
  return body.model || env.GEMINI_MODEL || DEFAULT_MODEL;
}

async function healthCheck(env) {
  if (!env.GEMINI_API_KEY) return {success:false,provider:"gemini",error:"GEMINI_API_KEY secret is missing"};
  const model = env.GEMINI_MODEL || DEFAULT_MODEL;
  const started = Date.now();
  const payload = {
    contents:[{role:"user",parts:[{text:'Reply with exactly {"status":"OK"}'}]}],
    generationConfig:{responseMimeType:"application/json",responseSchema:{type:"OBJECT",properties:{status:{type:"STRING"}},required:["status"]}}
  };
  const r = await callGemini(model,env.GEMINI_API_KEY,payload);
  if (!r.ok) return {success:false,provider:"gemini",model,http_code:r.status,latency_ms:Date.now()-started,error:r.data?.error?.message || "Gemini health check failed"};
  const text = extractText(r.data);
  let parsed = null; try { parsed = JSON.parse(cleanJson(text)); } catch (_) {}
  if (!parsed || parsed.status !== "OK") return {success:false,provider:"gemini",model,http_code:r.status,latency_ms:Date.now()-started,error:"Gemini returned an invalid health response",raw:text};
  return {success:true,provider:"gemini",model,http_code:r.status,latency_ms:Date.now()-started,message:"Gemini Worker and Gemini API are operational"};
}

async function research(body, env) {
  if (!env.GEMINI_API_KEY) return {success:false,provider:"gemini",error:"GEMINI_API_KEY secret is missing"};
  const model = modelName(body,env);
  const vehicle = body.vehicle || {};
  const field = body.field || {};
  const vehicleName = vehicle.name || [vehicle.brand,vehicle.model,vehicle.year].filter(Boolean).join(" ") || body.vehicle_name || "Unknown vehicle";
  const fieldName = field.label || field.name || body.field_name || "Unknown field";
  const prompt = buildPrompt(vehicleName,fieldName,vehicle,field,body.question || body.input || `Find the exact ${fieldName} for ${vehicleName}.`,body.instructions || "");
  const payload = geminiPayload(prompt,body.max_output_tokens || 2200,false);
  const r = await callGemini(model,env.GEMINI_API_KEY,payload);
  if (!r.ok) return {success:false,provider:"gemini",model,http_code:r.status,error:r.data?.error?.message || "Gemini request failed"};
  const parsed = parseJson(extractText(r.data));
  if (!parsed) return {success:false,provider:"gemini",model,http_code:r.status,error:"Gemini returned invalid structured JSON",raw_text:extractText(r.data)};
  return {success:true,provider:"gemini",model,http_code:r.status,result:parsed,grounding:r.data?.candidates?.[0]?.groundingMetadata || null,text:JSON.stringify(parsed)};
}

async function researchBatch(body, env) {
  if (!env.GEMINI_API_KEY) return {success:false,provider:"gemini",error:"GEMINI_API_KEY secret is missing"};
  const model = modelName(body,env);
  const vehicle = body.vehicle || {};
  const fields = Array.isArray(body.fields) ? body.fields.slice(0,10) : [];
  if (!fields.length) return {success:false,provider:"gemini",error:"No fields supplied"};
  const vehicleName = vehicle.name || [vehicle.brand,vehicle.model,vehicle.year].filter(Boolean).join(" ") || "Unknown vehicle";

  const fieldText = fields.map((f,i)=>`${i+1}. KEY=${f.key||f.name||""}; LABEL=${f.label||f.name||""}; TYPE=${f.type||""}; EXPECTED=${f.expected_data_type||""}; UNIT=${f.unit||""}; RULE=${JSON.stringify(f.rule||{})}`).join("\n");
  const prompt = `You are Tuningland's PRIMARY automotive research AI. You are the first and preferred research layer. Do not delegate the job to WordPress web scraping. Use your own knowledge only when it is reliable, and use Google Search grounding when verification or current/source evidence is needed. For each field, first classify the requested specification, then select the single most relevant page/section for that specification, then extract only evidence from that relevant section. Do not mix unrelated sections from the same site. Never use engine-oil data for transmission-oil fields, coolant for engine displacement, model names as values, or arbitrary numbers. Capacity, viscosity, standard, fluid type, displacement, wheelbase and product compatibility are different concepts. If multiple candidate pages exist, rank them by semantic relevance before extracting. If evidence is insufficient, found=false, value=null, needs_web_research=true. Apply the supplied field rule and learned rule. Return JSON only.\n\nVEHICLE: ${vehicleName}\n${JSON.stringify(vehicle)}\n\nFIELDS:\n${fieldText}\n\nOUTPUT SCHEMA:\n{"results":[{"field_key":"...","found":true,"value":"...","unit":"...","confidence":0,"field_type":"...","reason":"...","queries":["..."],"sources":[{"title":"...","url":"...","evidence":"..."}],"needs_web_research":false}]}`;
  const payload = geminiPayload(prompt,body.max_output_tokens || 5000,true);
  const r = await callGemini(model,env.GEMINI_API_KEY,payload);
  if (!r.ok) return {success:false,provider:"gemini",model,http_code:r.status,error:r.data?.error?.message || "Gemini batch request failed"};
  const parsed = parseJson(extractText(r.data));
  if (!parsed || !Array.isArray(parsed.results)) return {success:false,provider:"gemini",model,http_code:r.status,error:"Gemini returned invalid batch JSON",raw_text:extractText(r.data)};
  return {success:true,provider:"gemini",model,http_code:r.status,results:parsed.results,grounding:r.data?.candidates?.[0]?.groundingMetadata || null,text:JSON.stringify(parsed.results)};
}

function geminiPayload(prompt,maxTokens, batch) {
  return {
    contents:[{role:"user",parts:[{text:prompt}]}],
    generationConfig:{maxOutputTokens:Number(maxTokens),responseMimeType:"application/json"},
    tools:[{googleSearch:{}}]
  };
}

async function callGemini(model,key,payload) {
  const url="https://generativelanguage.googleapis.com/v1beta/models/"+encodeURIComponent(model)+":generateContent?key="+encodeURIComponent(key);
  const response=await fetch(url,{method:"POST",headers:{"Content-Type":"application/json","Accept":"application/json"},body:JSON.stringify(payload)});
  let data; try{data=await response.json();}catch(_){data={error:{message:"Invalid JSON from Gemini"}};}
  return {ok:response.ok,status:response.status,data};
}

function extractText(data) {
  let text="";
  for(const p of (data?.candidates?.[0]?.content?.parts || [])) if(p?.text) text += p.text;
  return text.trim();
}
function cleanJson(text){return String(text).replace(/^```json\s*/i,"").replace(/^```\s*/i,"").replace(/\s*```$/i,"").trim();}
function parseJson(text){try{return JSON.parse(cleanJson(text));}catch(_){return null;}}

function buildPrompt(vehicleName,fieldName,vehicle,field,question,instructions){
  return `You are a deterministic automotive data research agent for Tuningland.\nVehicle: ${vehicleName}\nVehicle data: ${JSON.stringify(vehicle)}\nField: ${fieldName}\nField metadata: ${JSON.stringify(field)}\nQuestion: ${question}\nAdditional instructions: ${instructions}\n\nRules: identify the exact field meaning before searching; prefer manufacturer/official sources, then reliable technical sources; use Google Search grounding when needed; select the page/section specifically relevant to this field; cite evidence; do not guess; do not confuse engine oil with transmission oil, brake fluid, coolant, displacement, viscosity or capacity; if the source says 10.5 L, never reduce it to 2 L without explicit evidence. Return a single JSON object matching the requested schema.`;
}

function jsonResponse(data,status,origin){return new Response(JSON.stringify(data),{status,headers:{"Content-Type":"application/json; charset=utf-8",...corsHeaders(origin)}});}
function corsResponse(body,status,origin){return new Response(body,{status,headers: corsHeaders(origin)});}
function corsHeaders(origin){const allow=ALLOWED_ORIGINS.includes(origin)?origin:ALLOWED_ORIGINS[0];return {"Access-Control-Allow-Origin":allow,"Access-Control-Allow-Methods":"POST, OPTIONS","Access-Control-Allow-Headers":"Content-Type, Authorization","Vary":"Origin"};}
