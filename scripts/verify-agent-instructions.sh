#!/usr/bin/env bash

set -euo pipefail

fail() {
    printf 'agent-check: %s\n' "$1" >&2
    exit 1
}

assert_adapter() {
    local directory="$1"
    local canonical="$directory/AGENTS.md"
    local adapter="$directory/CLAUDE.md"

    [ -f "$canonical" ] || fail "missing canonical instruction file: $canonical"
    [ -f "$adapter" ] || fail "missing Claude adapter: $adapter"
    [ "$(cat "$adapter")" = '@AGENTS.md' ] || fail "$adapter must contain only @AGENTS.md"
}

for directory in . .github Modules/Payments docs tests; do
    assert_adapter "$directory"
done

find . \
    \( -path './.git' -o -path './.claude' -o -path './.ai' -o -path './vendor' \
       -o -path './node_modules' -o -path './graphify-out' \) -prune \
    -o -name AGENTS.md -print \
    | while IFS= read -r canonical; do
        assert_adapter "$(dirname "$canonical")"
    done

find . \
    \( -path './.git' -o -path './.claude' -o -path './.ai' -o -path './vendor' \
       -o -path './node_modules' -o -path './graphify-out' \) -prune \
    -o -name CLAUDE.md -print \
    | while IFS= read -r adapter; do
        [ -f "$(dirname "$adapter")/AGENTS.md" ] \
            || fail "orphan Claude adapter without canonical AGENTS.md: $adapter"
    done

git check-ignore -q .claude/settings.local.json \
    || fail '.claude/settings.local.json must remain repository-ignored'

if git ls-files --error-unmatch .claude/settings.local.json >/dev/null 2>&1; then
    fail '.claude/settings.local.json must never be tracked'
fi

[ -f .claude/settings.json ] || fail 'missing shared .claude/settings.json'
[ -f .claude/skills/graphify/SKILL.md ] || fail 'missing project Graphify skill'

grep -q 'graphify hook-guard search' .claude/settings.json \
    || fail 'shared settings must keep the Graphify search guard'
grep -q 'graphify hook-guard read' .claude/settings.json \
    || fail 'shared settings must keep the Graphify read guard'
grep -q 'Read(graphify-out/\*\*)' .claude/settings.json \
    || fail 'shared settings must deny direct reads of generated Graphify output'

if grep -R -n -E '/Users/[^/]+/|/home/[^/]+/' .claude --exclude='settings.local.json'; then
    fail 'tracked Claude configuration must not contain machine-specific home paths'
fi

printf 'agent-check: canonical instructions, scoped adapters, and shared settings are consistent\n'
