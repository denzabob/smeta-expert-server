\# Agent operating rules



\## Primary operating mode for this repository



For any non-trivial task, especially architecture, pricing, estimate, evidence, report, import, or cross-layer work, the agent must follow this strict loop:



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

