# Expert Chat LLM profile

The `expert_chat` task profile is stored in `app_settings` under `llm.profiles.expert_chat`.
It contains `provider`, `model`, `enabled`, and `fallback_policy` (`none` or `global`).
Provider credentials, base URL, and transport settings remain in `llm.providers` or ENV.
There is no migration or automatic profile creation. A missing or disabled profile uses
the existing global LLM route and provider model.

With an active profile, the pinned model is used only for the task's primary provider.
`global` fallback appends the existing global fallback provider list when global mode
is `auto`; `none` uses the selected provider alone. Multimodal requests still stay
on the selected primary provider. Refreshing the model catalog only changes cache
data and never changes `app_settings`.

The RouterAI catalog is fetched from `GET /models` at the configured RouterAI base
URL. A successful response replaces the server cache; it is considered fresh for
four hours. The last valid response remains available if refresh fails. Admin search
and filters are processed on the server and return at most 40 models per page.
Unknown saved model IDs remain valid profile values.

Effective capability resolution order is: trusted fields from the cached RouterAI
catalog, explicit `services.<provider>.capability_overrides.<model>` booleans, then
the existing `LLMCapabilityCatalog` fallback. `pdf_ocr` is resolved separately as
the RouterAI file-parser gateway path, gated by `expert.pdf_ocr.enabled` and a
text-capable model. Text PDF after local extraction only needs `text_input`.
If the selected profile lacks streaming support, Expert waits for a synchronous
completion and sends the complete response in one SSE delta; no token streaming is
claimed. The admin UI warns before saving.

The admin-only API is under `/api/admin/llm-profiles/expert-chat` and
`/api/admin/llm-model-catalog/routerai`. Smoke requests accept the unsaved provider
and model draft. They use bundled fixtures, do not create Expert messages or project
materials, and return only status, time, latency, TTFT when applicable, and a safe
error code. The PDF/OCR smoke request can incur provider cost.
