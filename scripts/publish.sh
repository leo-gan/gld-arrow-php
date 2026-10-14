#!/usr/bin/env bash
# Ask Packagist to crawl the release tag. Register the repository once first.
# See docs/publishing.md. Pass --dry-run to print the request and not send it.
set -euo pipefail

root="$(cd "$(dirname "$0")/.." && pwd)"
cd "${root}"

dry_run=0
if [[ "${1:-}" == "--dry-run" ]]; then
  dry_run=1
elif [[ -n "${1:-}" ]]; then
  echo "usage: scripts/publish.sh [--dry-run]" >&2
  exit 2
fi

if ! command -v composer >/dev/null 2>&1; then
  echo "composer is not on PATH" >&2
  exit 1
fi

mapfile -t meta < <(python3 -c 'import json; c=json.load(open("composer.json")); print(c["name"]); print(c["version"])')
package_name="${meta[0]}"
version="${meta[1]}"

origin="$(git remote get-url origin)"
origin="${origin%.git}"
origin="${origin#git@github.com:}"
origin="${origin#https://github.com/}"
origin="${origin#http://github.com/}"
repo_url="https://github.com/${origin}"

if [[ "${GITHUB_REF_TYPE:-}" == "tag" ]]; then
  tag_name="${GITHUB_REF_NAME}"
  tag_version="${tag_name#v}"
  if [[ "${tag_version}" != "${version}" ]]; then
    echo "git tag ${tag_name} does not match composer.json version ${version}" >&2
    exit 1
  fi
else
  tag_name="${version}"
fi

composer validate --no-interaction --no-check-publish --no-check-version --strict

block="$(cat <<EOF
Packagist package \`${package_name}\`.

\`\`\`bash
composer require ${package_name}
\`\`\`

Release tag \`${tag_name}\` matches \`composer.json\` version \`${version}\`.
EOF
)"
printf '%s\n' "${block}"

if [[ "${dry_run}" -eq 1 ]]; then
  echo "dry-run: Packagist was not called"
  exit 0
fi

if [[ -z "${PACKAGIST_USERNAME:-}" || -z "${PACKAGIST_TOKEN:-}" ]]; then
  cat >&2 <<EOF
PACKAGIST_USERNAME and PACKAGIST_TOKEN are required.
Submit ${repo_url} at https://packagist.org/packages/submit
and create a token at https://packagist.org/profile/
The steps are in docs/publishing.md.
EOF
  exit 1
fi

REPO_URL="${repo_url}" python3 - <<'PY'
import json
import os
import sys
import urllib.error
import urllib.parse
import urllib.request

username = os.environ["PACKAGIST_USERNAME"]
token = os.environ["PACKAGIST_TOKEN"]
repo = os.environ["REPO_URL"]
query = urllib.parse.urlencode({"username": username, "apiToken": token})
url = f"https://packagist.org/api/update-package?{query}"
payload = json.dumps({"repository": {"url": repo}}).encode()
req = urllib.request.Request(
    url,
    data=payload,
    headers={"Content-Type": "application/json", "User-Agent": "gld-publish"},
)
try:
    with urllib.request.urlopen(req) as resp:
        body = resp.read().decode()
except urllib.error.HTTPError as exc:
    print(exc.read().decode(errors="replace"), file=sys.stderr)
    raise SystemExit(1) from exc
print(body)
parsed = json.loads(body)
if parsed.get("status") not in (None, "success"):
    raise SystemExit(1)
PY

if [[ "${GITHUB_ACTIONS:-}" == "true" && "${GITHUB_REF_TYPE:-}" == "tag" ]]; then
  printf '%s\n' "${block}" | python3 scripts/github_release.py notes --tag "${tag_name}"
fi
