Implement only the requested block from the approved plan.



Block:

\[PASTE BLOCK HERE]



Mandatory constraints:

\- execute only this block

\- minimal localized diff

\- no unrelated refactors

\- preserve backward compatibility

\- do not rewrite large legacy files unless explicitly required

\- keep old flows operational during transition

\- no silent scope expansion

\- if hidden dependency appears, stop and report instead of broadening the block



Before coding, return in this exact format:



1\. Short plan

2\. Files to change

3\. Acceptance criteria

4\. Out of scope

5\. Risks / hidden dependency check



After that, implement.



After implementation, return in this exact format:



1\. Files changed

2\. What was implemented

3\. Exact endpoints changed

4\. Exact models / migrations / services / UI contracts changed

5\. Exact tests added or updated

6\. Checks run

7\. Known limitations

8\. Manual verification steps

9\. Suggested next block



Rules:

\- do not broaden the block because “it is cleaner”

\- do not refactor adjacent code unless required for this block

\- do not rename public APIs unless the block explicitly requires it

\- do not modify unrelated frontend state or layouts

\- do not change PDF/report flows unless the block explicitly includes them

\- if acceptance criteria cannot be met without extra scope, stop and report that explicitly before coding

