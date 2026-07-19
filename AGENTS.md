# Repository Instructions

- Whenever you create a database migration script, run it.
- Before cleaning, stashing, resetting, switching branches, or otherwise changing a dirty worktree, first preserve the current app state on a branch or commit so the live working code cannot unexpectedly fall back to old `master` code.
- Before making code changes, run `git status --short --branch` and confirm the current branch.
- Do not make feature or fix edits directly on `master`; create or switch to a feature branch first.
- Keep the worktree clean by committing coherent chunks of completed work instead of leaving important app changes uncommitted.
- Before switching tasks, switching branches, cleaning files, or ending a substantial work session, run `git status --short --branch` and either commit intentional changes or stash them with a descriptive message.
- Do not commit generated screenshots, browser session files, logs, caches, or temporary debug output unless the user explicitly asks for them or they are intentional product assets.
- When generated files appear during testing, prefer adding an appropriate `.gitignore` rule rather than allowing repeat dirty worktree noise.
- When emitting Codex app git directives such as `::git-stage{...}`, `::git-commit{...}`, `::git-create-branch{...}`, or `::git-push{...}`, use forward slashes in Windows `cwd` paths, for example `cwd="C:/xampp/htdocs/crm"`. Do not emit backslash paths such as `cwd="C:\xampp\htdocs\crm"` because Codex Desktop can fail to render old threads when parsing those directives.
