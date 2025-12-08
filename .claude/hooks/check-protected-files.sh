#!/bin/bash
#
# Protected Files Hook
# Requires explicit user confirmation when editing critical configuration files
#
# Trigger: PreToolUse on Write|Edit tools
# Purpose: Prevent accidental changes to linter configs in auto-accept mode
#

set -euo pipefail

# =============================================================================
# PROTECTED FILES LIST
# Add or remove files from this array as needed
# =============================================================================
PROTECTED_FILES=(
    "phparkitect.php"
    "phpstan.neon"
    "pint.json"
    "config/insights.php"
    "deptrac.yaml"
    "composer.json"
)

# =============================================================================
# HOOK LOGIC
# =============================================================================

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

# Read JSON input from stdin
INPUT=$(cat)

# Extract file path from tool input
FILE_PATH=$($JQ -r '.tool_input.file_path // empty' <<< "$INPUT")

# If no file path, skip silently
if [[ -z "$FILE_PATH" ]]; then
    exit 0
fi

# Check if the file matches any protected file
# Supports both exact match and ends-with match (for paths like ./phparkitect.php)
MATCHED_FILE=""
for protected in "${PROTECTED_FILES[@]}"; do
    # Exact match
    if [[ "$FILE_PATH" == "$protected" ]]; then
        MATCHED_FILE="$protected"
        break
    fi
    # Ends-with match (handles relative/absolute paths)
    if [[ "$FILE_PATH" == *"/$protected" ]] || [[ "$FILE_PATH" == "./$protected" ]]; then
        MATCHED_FILE="$protected"
        break
    fi
done

# If no match, exit silently (allow the operation)
if [[ -z "$MATCHED_FILE" ]]; then
    exit 0
fi

# Build the reason message
REASON="🔒 PROTECTED FILE: '${FILE_PATH}' — This is a linter configuration file. Changes affect code quality enforcement project-wide. Please confirm this change is intentional."

# Use jq to construct valid JSON (handles all escaping automatically)
# hookEventName is REQUIRED for PreToolUse hooks
$JQ -n \
    --arg reason "$REASON" \
    '{hookSpecificOutput: {hookEventName: "PreToolUse", permissionDecision: "ask", permissionDecisionReason: $reason}}'
