#!/usr/bin/env bash

set -Eeuo pipefail

UPSTREAM_URL="${UPSTREAM_URL:-https://github.com/kantorge/yaffa.git}"
UPSTREAM_REMOTE="${UPSTREAM_REMOTE:-upstream}"
UPSTREAM_BRANCH="${UPSTREAM_BRANCH:-develop}"
LOCAL_DEVELOP="${LOCAL_DEVELOP:-develop}"
ORIGIN_REMOTE="${ORIGIN_REMOTE:-origin}"

FEATURE_BRANCHES=(
  "add_reconcile"
  "investment-active-first"
)

usage() {
  cat <<EOF
Usage:
  $(basename "$0") [--push]

Options:
  --push    Push the updated local ${LOCAL_DEVELOP} branch to ${ORIGIN_REMOTE}

Environment overrides:
  UPSTREAM_URL
  UPSTREAM_REMOTE
  UPSTREAM_BRANCH
  LOCAL_DEVELOP
  ORIGIN_REMOTE

Example:
  ./sync-yaffa.sh
  ./sync-yaffa.sh --push
EOF
}

PUSH=false

while [[ $# -gt 0 ]]; do
  case "$1" in
    --push)
      PUSH=true
      shift
      ;;
    --help|-h)
      usage
      exit 0
      ;;
    *)
      echo "Unknown option: $1"
      usage
      exit 1
      ;;
  esac
done

die() {
  echo
  echo "ERROR: $*" >&2
  exit 1
}

run() {
  echo
  echo "+ $*"
  "$@"
}

REPO_ROOT="$(git rev-parse --show-toplevel 2>/dev/null)" \
  || die "This script must be run inside a Git repository."

cd "$REPO_ROOT"

echo "Repository: $REPO_ROOT"

if [[ -n "$(git status --porcelain)" ]]; then
  die "Your working tree is not clean. Commit or stash your changes before syncing."
fi

if ! git remote get-url "$UPSTREAM_REMOTE" >/dev/null 2>&1; then
  echo "Adding ${UPSTREAM_REMOTE} remote: ${UPSTREAM_URL}"
  run git remote add "$UPSTREAM_REMOTE" "$UPSTREAM_URL"
else
  CURRENT_UPSTREAM_URL="$(git remote get-url "$UPSTREAM_REMOTE")"
  echo "Using existing ${UPSTREAM_REMOTE} remote: ${CURRENT_UPSTREAM_URL}"
fi

if ! git remote get-url "$ORIGIN_REMOTE" >/dev/null 2>&1; then
  echo "Warning: remote '${ORIGIN_REMOTE}' does not exist."
  if [[ "$PUSH" == true ]]; then
    die "Cannot use --push without an '${ORIGIN_REMOTE}' remote."
  fi
fi

echo
echo "Fetching ${UPSTREAM_REMOTE}/${UPSTREAM_BRANCH}..."
run git fetch "$UPSTREAM_REMOTE" "$UPSTREAM_BRANCH"

if ! git show-ref --verify --quiet "refs/heads/${LOCAL_DEVELOP}"; then
  echo
  echo "Creating local ${LOCAL_DEVELOP} from ${UPSTREAM_REMOTE}/${UPSTREAM_BRANCH}..."
  run git switch --create "$LOCAL_DEVELOP" \
    "${UPSTREAM_REMOTE}/${UPSTREAM_BRANCH}"
else
  run git switch "$LOCAL_DEVELOP"
fi

echo
echo "Merging latest ${UPSTREAM_REMOTE}/${UPSTREAM_BRANCH} into ${LOCAL_DEVELOP}..."
if ! git merge --no-ff --no-edit \
  "${UPSTREAM_REMOTE}/${UPSTREAM_BRANCH}" \
  -m "Merge ${UPSTREAM_REMOTE}/${UPSTREAM_BRANCH} into ${LOCAL_DEVELOP}"; then

  echo
  echo "The upstream merge has conflicts."
  echo "Resolve them, then run:"
  echo "  git add <resolved-files>"
  echo "  git commit"
  echo
  echo "After that, rerun this script to merge the remaining branches."
  exit 1
fi

for FEATURE_BRANCH in "${FEATURE_BRANCHES[@]}"; do
  if ! git show-ref --verify --quiet "refs/heads/${FEATURE_BRANCH}"; then
    die "Required local branch '${FEATURE_BRANCH}' does not exist."
  fi

  echo
  echo "Merging ${FEATURE_BRANCH} into ${LOCAL_DEVELOP}..."
  if ! git merge --no-ff --no-edit "$FEATURE_BRANCH" \
    -m "Merge ${FEATURE_BRANCH} into ${LOCAL_DEVELOP}"; then

    echo
    echo "The merge of ${FEATURE_BRANCH} has conflicts."
    echo "Resolve them, then run:"
    echo "  git add <resolved-files>"
    echo "  git commit"
    echo
    echo "Then rerun this script."
    exit 1
  fi
done

if [[ "$PUSH" == true ]]; then
  echo
  echo "Pushing ${LOCAL_DEVELOP} to ${ORIGIN_REMOTE}..."
  run git push "$ORIGIN_REMOTE" "$LOCAL_DEVELOP"
fi

echo
echo "Sync complete."
echo
echo "Current branch:"
git branch --show-current

echo
echo "Recent history:"
git --no-pager log --oneline --decorate -8

echo
echo "Next time, run:"
if [[ "$PUSH" == true ]]; then
  echo "  ./sync-yaffa.sh --push"
else
  echo "  ./sync-yaffa.sh"
fi