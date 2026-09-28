# loop-work — vendored copy

- **version:** 0.5.4
- **source:** `/home/samir/x/SKILLS/skill-LOOP` (commit `cb511e9`)
- **installed:** 2026-09-28

A **snapshot** of `skill/loop/` from the skill-LOOP repository. Do not edit it
here — change it upstream and re-vendor with `vendor.sh`, or the next update
overwrites your change silently.

## Why `.claude/prompts/` exists next to it

`hooks/loop-stop.py` resolves its prompt templates three levels up from
`skill/loop/`, which from here lands in `<repo>/.claude/prompts/`. That is why
`continuacao.md` and `reabastecimento.md` are vendored there; the copy is
incomplete without them.

## No hook is registered per repository

The `Stop` hook is registered **globally**, by `install.sh`, in the Claude Code
config dir in use — and it points at the skill-LOOP repository, not at this copy.
It is inert in any repository without an active `.loop/STATE.json`, so there is
nothing to register here; registering it would fire the hook twice.

One consequence worth knowing: this copy takes precedence over the global symlink
for what the agent **reads** (`SKILL.md`, `loop_ctl.py`), while the hook that
drives the continuation is always the upstream one. After an upstream bump, this
copy is the stale half until `vendor.sh` runs again.
