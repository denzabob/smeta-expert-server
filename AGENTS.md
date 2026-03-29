\# Agent operating rules



\## Before editing

\- Read the full task before making changes

\- Inspect existing architecture and reuse current patterns where reasonable

\- For tasks affecting backend, frontend, PDF, or extension together, split work into phases

\- Start with a concise implementation plan and affected files list



\## Editing rules

\- Prefer additive changes over destructive rewrites

\- Keep migrations explicit and reversible where possible

\- Preserve legacy flows during transition

\- Avoid unrelated formatting changes

\- Do not modify generated or vendor files unless explicitly required



\## Architecture rules

\- New domain flows should be introduced through new services, adapters, or modules first

\- Large legacy files should be extended carefully; avoid rewriting the whole file

\- Keep API contracts stable unless the task explicitly includes contract updates

\- For data model changes, define entities, relations, lifecycle, and backward compatibility first



\## Task execution

\- For large work, first propose:

&#x20; 1. architecture summary

&#x20; 2. entities / migrations

&#x20; 3. API changes

&#x20; 4. UI changes

&#x20; 5. rollout plan

\- Then wait for confirmation or proceed only with the requested block



\## Validation

\- Run targeted checks after changes

\- Summarize assumptions and known risks

