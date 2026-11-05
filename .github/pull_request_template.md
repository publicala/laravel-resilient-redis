<!--
Publica.la pull request template.
Reference copy: https://github.com/publicala/.github/blob/main/PULL_REQUEST_TEMPLATE.md

Delete every section that does not apply. Never leave a section holding "none" or "N/A". The Deployment line is the one exception.
The title carries more weight than anything below it. Imperative, complete sentence, understandable on its own in `git log`.
-->

<!--
Links. One line per relation. Delete the lines that do not apply.
Name the tool and the project in every link text, also for this repository: "GitHub repo#123", "Linear DEV-123", "Nightwatch app #123", "Sentry project PROJECT-1A2", "Slack #channel thread".
Write every link as a Markdown link with the full URL: [GitHub repo#123](https://github.com/publicala/repo/pull/123). GitHub turns a bare `#123` into this repository's issue or PR. A GitHub closing reference is the one exception: it stays bare.
- Closes: what this PR resolves and marks done (Linear, Nightwatch, Sentry). A GitHub issue closes on merge only with GitHub's own syntax on its own line, and only when the PR targets the default branch: `- Closes publicala/repo#123`.
- Related: context this PR does not resolve (Slack thread, design doc, a partly addressed issue).
- Merge first: a PR in another repository that must merge before this one.
- Stacked on: the parent PR of a stack. The base branch is the parent's branch. Retarget to the default branch only after every PR below this one in the stack has merged there.
-->

- Closes:
- Related:
- Merge first:
- Stacked on:

## Summary

<!-- One sentence: what changes, for whom. -->

## Why

<!-- What the diff cannot show: the problem, why this approach over the alternatives, the trade-off or limitation you accepted. Keep the load-bearing fact inline (the error, the decision, the number) so the PR still reads when the link dies or the reader lacks access. Skip only for true one-liners. -->

## Changes

<!-- What changed against the target branch, from a reviewer's perspective: files, classes, behavior. One idea per bullet, nested sub-bullets when a single item carries several. Do not narrate the diff, and do not use self-referential labels like "Setup overhaul" that name a goal instead of describing the change. On a multi-file diff, name the first file to read: "Start here: file-a → file-b". In a stacked PR, say "Review only this PR's commits" and give its place in the stack (2/3). -->

-

## Testing

<!-- What you ran and what it showed. Results, not a list of commands. Name devices, browsers, and themes for UI changes. -->

## Screenshots

<!--
UI changes only. A Before/After table, desktop and mobile, light and dark when the change touches them. Drag a video or GIF into the editor when the change moves.
| | Before | After |
| --- | --- | --- |
| Desktop, light | <img width="320" src="..."> | <img width="320" src="..."> |
| Mobile, dark | <img width="200" src="..."> | <img width="200" src="..."> |
-->

## Risk and rollback

<!-- Optional. What could break, how you would notice, and how to undo it. -->

<!-- One line naming only the steps that apply: migrations, cache or config clear, env vars or feature flags, a PR to merge first, what to monitor after release. Write "Deployment: none" when nothing applies. -->

Deployment:
