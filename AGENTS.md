\# Agent operating rules



\## Repository context



This repository is a production SaaS system for estimate calculation, expert pricing, furniture-related evidence collection, reporting, verification, and supporting business workflows.



Main stack:

\- Laravel / PHP backend

\- Vue 3 frontend

\- Vuetify UI layer

\- PDF generation

\- import/export flows

\- browser extension integration

\- revision / evidence / verification flows



This is not a generic CRUD project. Many tasks affect cross-layer business behavior, legal-style documents, pricing logic, or backward compatibility of existing workflows.



\## Primary operating mode for this repository



For any non-trivial task, especially architecture, pricing, estimate, evidence, report, import, PDF, extension, revision, verification, or cross-layer work, the agent must follow this strict loop:



1\. analyze current code and structure first

2\. return only the analysis result for the requested scope

3\. wait for evaluation / corrected task unless the user explicitly asked to continue immediately

4\. implement only the approved block

5\. report exact changes, limits, risks, and checks

6\. stop and wait for the next block



Do not merge analysis, architecture redesign, and implementation into one uncontrolled pass.



\## Block discipline



Every substantial task must be split into bounded blocks.



A block must have:

\- one clear goal

\- explicit in-scope files/modules

\- explicit out-of-scope items

\- backward-compatibility expectation

\- acceptance criteria

\- targeted validation



If the requested block is too broad, the agent must narrow it before coding.



If hidden dependencies are discovered, stop and report them instead of expanding the block silently.



\## Before editing



Always do all of the following before making code changes:

\- read the full task

\- inspect the current implementation

\- identify affected files

\- identify touched entities, migrations, services, APIs, UI, PDF, extension if relevant

\- identify backward compatibility risks

\- identify what will NOT be changed in this block



For cross-cutting tasks, do not code before returning the analysis/block split first.



\## Analysis rules



Analysis must be repository-grounded, not speculative.



Always separate:

\- Observed — directly confirmed in code/schema/files

\- Inferred — reasoned conclusion based on observed implementation

\- Missing / not found — required concept not found in the repository



Do not invent architecture that is not present in code.

Do not assume a future refactor is already safe.



\## Editing rules



\- prefer additive changes over destructive rewrites

\- keep diffs minimal and localized

\- preserve legacy flows during transition

\- avoid unrelated formatting changes

\- do not modify generated or vendor files unless explicitly required

\- do not rewrite large legacy files wholesale if a service, adapter, helper, or focused component can solve the block

\- keep public contracts stable unless the block explicitly includes contract changes



\## Architecture rules



When introducing new domain behavior:

\- define entities, relations, lifecycle, and compatibility first

\- prefer new services, resolvers, adapters, or mappers over invasive rewrites

\- keep old and new flows side by side during migration where reasonable

\- do not collapse multiple domain problems into one implementation block



\## High-risk domains



Treat the following as high-risk cross-domain areas:

\- estimate totals and pricing logic

\- evidence collection, linkage, and freshness logic

\- revision runs and revision item resolution

\- PDF generation and legal-style evidence appendices

\- import/export pipelines

\- browser extension request/response contracts

\- public verification flows

\- project settings that affect pricing, evidence, or document generation



For these areas:

\- prefer analysis-first

\- avoid silent scope expansion

\- do not replace existing flows without explicit approval

\- document compatibility and rollback considerations



\## UI and design-system rules



This repository is moving toward a unified MD3-inspired semantic design system on top of Vue 3 + Vuetify.



For UI tasks:

\- prefer global theme roles, semantic tokens, and shared layout wrappers over page-local styling

\- do not introduce one-off visual patterns if the problem can be solved by extending the shared design system

\- do not hardcode colors, radii, shadows, or spacing when reusable tokens/components already exist

\- preserve high-density enterprise usability; do not make dense operational screens unnecessarily airy or decorative

\- keep desktop and mobile behavior stable

\- preserve predictable states: hover, focus, disabled, error, loading

\- for major UI changes, identify whether the correct scope is foundation/shared component level or feature-screen level before editing



If a new visual pattern is needed:

\- first define where it belongs in the shared system

\- then apply it in the concrete screen

\- do not create a local mini-design-system inside one page



\## Dense operational UI rule



This repository contains dense operational screens for estimate work, evidence review, settings, admin workflows, and data-heavy editing.



For such screens:

\- preserve information density and scan speed

\- do not import auth/marketing page spacing or composition blindly

\- prefer compact clarity over decorative spacing

\- any global token or primitive change that affects dense screens must be called out explicitly as a risk



\## UI validation for foundation / theme blocks



For any block that changes theme, tokens, global styles, or shared UI primitives, targeted validation must include:



\- build result

\- manual smoke-check plan for the pilot screen

\- manual smoke-check plan for 2-3 existing non-pilot screens

\- explicit list of states not verified yet



At minimum, manual verification should mention:

\- desktop

\- mobile

\- hover

\- focus

\- error

\- disabled

\- loading

\- dark/light theme if applicable



\## Global UI blast-radius rule



If the task touches global theme files, semantic tokens, design-system styles, shared visual primitives, or shell surfaces, the agent must explicitly report:



\- which existing UI areas may be affected indirectly

\- which existing screens should be manually smoke-checked

\- which visual changes are expected and acceptable

\- which changes would count as regressions



Do not treat a foundation-level change as isolated to the requested pilot screen unless that isolation is confirmed in code.



\## Legacy migration rules



This repository contains active legacy flows that must often coexist with newer implementations.



Therefore:

\- do not remove legacy code paths unless the task explicitly includes removal

\- do not assume older flows are unused

\- prefer bridges, adapters, feature flags, or side-by-side migration paths

\- if a new implementation overlaps an old one, explicitly document both paths and compatibility expectations



\## DB / migration safety



When touching schema or persistence:

\- do not introduce destructive migrations unless explicitly justified

\- call out any drop/rename/backfill risk clearly

\- include migration safety notes in the report

\- state whether rollback is straightforward or risky

\- avoid broad data rewrites inside a mixed implementation block



\## PDF / document rules



PDF and formal document outputs are business-critical and may be legally important.



When changing PDF or document generation:

\- preserve document meaning, structure, and traceability

\- avoid mixing domain recalculation and template redesign in the same block

\- consider paginated A4 behavior, section stability, and backward compatibility

\- explicitly state what visual or semantic output changed



\## Extension / API compatibility



When changing browser extension or related API endpoints:

\- preserve request/response compatibility unless explicitly approved

\- do not silently rename payload fields or semantic statuses

\- validate not only controller logic but the end-to-end interaction chain where possible

\- call out any user-visible change in extension behavior



\## Required response format for analysis tasks



Return in this order:

1\. goal summary

2\. current architecture involved

3\. affected files/directories

4\. risks / backward compatibility concerns

5\. proposed implementation blocks

6\. recommended next block



\## Required response format for implementation tasks



Before coding, return:

1\. short plan

2\. files to change

3\. acceptance criteria

4\. out-of-scope confirmation

5\. risks



After coding, return:

1\. files changed

2\. what was implemented

3\. exact endpoints changed

4\. exact migrations/models/contracts changed

5\. exact tests added

6\. checks run

7\. known limitations

8\. manual verification steps

9\. suggested next block



\## Validation



\- run targeted checks only for the selected block

\- report exactly what was run

\- report what was not run

\- if migrations changed, include migration safety notes

\- if no checks were run, state why



\## Hard prohibitions



\- no silent scope expansion

\- no broad refactor disguised as a block

\- no implementation before repository inspection for non-trivial tasks

\- no replacing legacy flow unless explicitly requested

\- no hidden API contract changes

\- no unsupported claims of completion or verification

