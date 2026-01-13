#!/usr/bin/env bash
# Notifies JetBrains IDE about files changed in the last git commit
# Triggers UI refresh for git panel and file status

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/../.." && pwd)"
HOOK_SCRIPT="$PROJECT_DIR/.claude/hooks/post-tool-call.py"

# Ensure CLAUDE_PROJECT_DIR is set for the hook
export CLAUDE_PROJECT_DIR="${CLAUDE_PROJECT_DIR:-$PROJECT_DIR}"

if [[ ! -f "$HOOK_SCRIPT" ]]; then
    echo "Error: Hook script not found at $HOOK_SCRIPT" >&2
    exit 1
fi

# Check if we're in a git repository
if ! git rev-parse --git-dir > /dev/null 2>&1; then
    echo "Error: Not in a git repository" >&2
    exit 1
fi

# Get files from the last commit
files=$(git diff-tree --no-commit-id --name-only -r HEAD 2>/dev/null || true)

if [[ -z "$files" ]]; then
    echo "No files in last commit to refresh"
    exit 0
fi

# JSON encoding function - uses jq if available, falls back to simple echo
json_payload() {
    local filepath="$1"
    if command -v jq &> /dev/null; then
        jq -n --arg path "$filepath" '{"tool_name":"Edit","tool_input":{"file_path":$path}}'
    else
        # Simple fallback - works for paths without quotes/backslashes
        echo "{\"tool_name\":\"Edit\",\"tool_input\":{\"file_path\":\"$filepath\"}}"
    fi
}

count=0
while IFS= read -r file; do
    if [[ -n "$file" ]]; then
        # Send notification to JetBrains
        json_payload "$PROJECT_DIR/$file" | python3 "$HOOK_SCRIPT" 2>/dev/null || true
        ((count++)) || true
    fi
done <<< "$files"

echo "Refreshed $count file(s) in IDE"