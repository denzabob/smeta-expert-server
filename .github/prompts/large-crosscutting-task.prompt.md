This is a large cross-cutting task affecting multiple layers of the system.



Do not attempt full implementation in one pass.



Your workflow must be:

1\. inspect current architecture

2\. identify domain model changes

3\. identify persistence/schema changes

4\. identify service/controller/API changes

5\. identify frontend/UI changes

6\. identify PDF/report changes if applicable

7\. identify browser extension/plugin changes if applicable

8\. identify backward compatibility strategy

9\. split work into bounded implementation blocks

10\. wait for the selected block before coding



Task:

Introduce a generic evidence domain model for estimate revision/evidence/PDF flow.



Requirements:

\- preserve legacy RevisionRun and material\_price\_history compatibility

\- support evidence records and assets

\- support future extension to labor rates, expenses, and browser extension capture

\- provide phased rollout

\- do not implement yet



Return:

1\. Goal summary

2\. Current architecture involved

3\. Proposed target architecture

4\. Entities and migrations

5\. API contracts

6\. Frontend impact

7\. Reporting/PDF impact

8\. Extension impact

9\. Risks

10\. Implementation blocks

11\. Recommended first block

