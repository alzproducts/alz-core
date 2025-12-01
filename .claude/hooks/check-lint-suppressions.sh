#!/bin/bash
#
# Lint Suppression Detection Hook
# Detects when Claude adds lint suppression comments and asks for user approval
#
# Trigger: PreToolUse on Write|Edit tools
# Purpose: Enforce CLAUDE.md policy against bypassing linters without approval
#

set -euo pipefail

# Find jq - check common locations
JQ=""
for path in "/opt/homebrew/bin/jq" "/usr/local/bin/jq" "/usr/bin/jq" "$(which jq 2>/dev/null)"; do
    if [[ -x "$path" ]]; then
        JQ="$path"
        break
    fi
done

# If jq not found, skip gracefully
if [[ -z "$JQ" ]]; then
    exit 0
fi

# Read JSON input from stdin with timeout
INPUT=$(cat)

# Extract tool name
TOOL_NAME=$($JQ -r '.tool_name // empty' <<< "$INPUT")

# Determine what content to check based on tool type
if [[ "$TOOL_NAME" == "Write" ]]; then
    CONTENT=$($JQ -r '.tool_input.content // empty' <<< "$INPUT")
    FILE_PATH=$($JQ -r '.tool_input.file_path // empty' <<< "$INPUT")
elif [[ "$TOOL_NAME" == "Edit" ]]; then
    CONTENT=$($JQ -r '.tool_input.new_string // empty' <<< "$INPUT")
    FILE_PATH=$($JQ -r '.tool_input.file_path // empty' <<< "$INPUT")
else
    exit 0
fi

# If no content to check, skip
if [[ -z "$CONTENT" ]]; then
    exit 0
fi

# Define suppression patterns to detect
PATTERNS=(
    "@phpstan-ignore-line"
    "@phpstan-ignore-next-line"
    "@phpstan-ignore "
    "@psalm-suppress"
    "@noinspection"
)

# Check for each pattern
FOUND_PATTERNS=()
for pattern in "${PATTERNS[@]}"; do
    if grep -q "$pattern" <<< "$CONTENT"; then
        FOUND_PATTERNS+=("$pattern")
    fi
done

# If no suppressions found, exit silently (allow the operation)
if [[ ${#FOUND_PATTERNS[@]} -eq 0 ]]; then
    exit 0
fi

# Build comma-separated list (avoids newline issues in JSON)
PATTERN_LIST=$(IFS=', '; echo "${FOUND_PATTERNS[*]}")

# Build the reason message
REASON="⚠️ LINT SUPPRESSION DETECTED in '${FILE_PATH}' — Found: ${PATTERN_LIST} — Per project policy, lint suppressions require explicit approval."

# Use jq to construct valid JSON (handles all escaping automatically)
# hookEventName is REQUIRED for PreToolUse hooks
$JQ -n \
    --arg reason "$REASON" \
    '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: "ask", permissionDecisionReason: $reason}}'
