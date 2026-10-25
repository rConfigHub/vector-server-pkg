#!/usr/bin/env bash
#
# rConfig Vector agent installer (RCO-1817).
#
# rConfig serves this file unchanged: it is never rendered or edited per
# agent. The install command checks its SHA256 against the value published
# in the rConfig docs before running it, so this script does not trust
# anything the rConfig server sends back:
#   - the agent binary is installed only if its SHA256 is in the pinned
#     release list below, and never over a newer pinned release
#   - the server URL, agent ID, bootstrap token and returned API key are
#     validated before anything is written
#
# Usage:
#   sudo bash vector-agent-install.sh --server https://rconfig.example.com \
#       --agent-id 12 --token <bootstrap token> [--insecure-tls]
#
# --insecure-tls skips TLS certificate checks, for an rConfig server with a
# self-signed certificate. The binary is still checked against the pinned list.

set -euo pipefail
umask 077

# BEGIN PINNED AGENT RELEASES
# One line per published Linux agent release: version, then SHA256.
# Taken from the agent release build, never from an rConfig server.
PINNED_RELEASES="
2.0.0 16317c42dd3390b278e9fcf1bcd84715b301c7f7d1cc08595de9c750962a27ee
1.1.4 c629244e9c5f922b5da8be206180774eeb3d0bb029a27a28bcfabf56953de5b4
1.1.3 59e0b3bfff4e468121e6eac6e56dd744a36f0dcf2e542bd30486aca1007b4826
1.1.2 2bd83dffc44431de8088bf07f740c38543f454b8ec2fa2af05a783957f868422
1.1.1 84da431d66cad82783a40cd144ec5d40c82475a108dd81a79580d3941493970c
"
# END PINNED AGENT RELEASES

INSTALL_ROOT="${VECTOR_AGENT_INSTALL_ROOT:-}"
BASE_DIR="${INSTALL_ROOT}/usr/local/bin/rconfig"
ACTIVE_DIR="${BASE_DIR}/activeagent"
DATA_DIR="${BASE_DIR}/data"
BIN_PATH="${ACTIVE_DIR}/vectoragent"
ENV_PATH="${ACTIVE_DIR}/.env"
UNIT_PATH="${INSTALL_ROOT}/etc/systemd/system/vectoragent.service"

fail() {
    printf 'Error: %s\n' "$1" >&2
    exit 1
}

usage() {
    printf 'Usage: sudo bash %s --server https://<rconfig server> --agent-id <id> --token <bootstrap token> [--insecure-tls]\n' "$0"
}

SERVER_URL=""
AGENT_ID=""
BOOTSTRAP_TOKEN=""
INSECURE_TLS=0

while [ "$#" -gt 0 ]; do
    case "$1" in
        --server)
            [ "$#" -ge 2 ] || fail "--server needs a value."
            SERVER_URL="$2"
            shift 2
            ;;
        --agent-id)
            [ "$#" -ge 2 ] || fail "--agent-id needs a value."
            AGENT_ID="$2"
            shift 2
            ;;
        --token)
            [ "$#" -ge 2 ] || fail "--token needs a value."
            BOOTSTRAP_TOKEN="$2"
            shift 2
            ;;
        --insecure-tls)
            INSECURE_TLS=1
            shift
            ;;
        -h | --help)
            usage
            exit 0
            ;;
        *)
            usage >&2
            fail "Unknown option: $1"
            ;;
    esac
done

SERVER_URL="${SERVER_URL%/}"
SERVER_URL_PATTERN='^https://(\[[0-9A-Fa-f:.]+\]|[A-Za-z0-9]([A-Za-z0-9.-]*[A-Za-z0-9])?)(:[0-9]{1,5})?$'
[[ "$SERVER_URL" =~ $SERVER_URL_PATTERN ]] || fail "--server must be https://<host> or https://<host>:<port>, with no path."
[[ "$AGENT_ID" =~ ^[1-9][0-9]{0,9}$ ]] || fail "--agent-id must be a whole number."
[[ "$BOOTSTRAP_TOKEN" =~ ^[A-Za-z0-9]{32,128}$ ]] || fail "--token is not a valid bootstrap token."

[ "$(id -u)" -eq 0 ] || fail "Run this installer as root, for example with sudo."

for required in curl sha256sum sort awk install mktemp; do
    command -v "$required" >/dev/null 2>&1 || fail "$required is required but was not found."
done

CURL_OPTS=(-fsSL --proto '=https' --proto-redir '=https')
SSL_VERIFY=true
if [ "$INSECURE_TLS" -eq 1 ]; then
    CURL_OPTS+=(-k)
    SSL_VERIFY=false
    echo "Warning: TLS certificate checks are off (--insecure-tls)." >&2
fi

TMP_DIR="$(mktemp -d)"
cleanup() {
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

release_version_for() {
    printf '%s\n' "$PINNED_RELEASES" | awk -v sha="$1" 'NF == 2 && $2 == sha && ! found { print $1; found = 1 }'
}

version_is_older() {
    [ "$1" != "$2" ] && [ "$(printf '%s\n%s\n' "$1" "$2" | sort -V | head -n 1)" = "$1" ]
}

echo "Downloading Vector agent binary..."
curl "${CURL_OPTS[@]}" -o "$TMP_DIR/vectoragent" "${SERVER_URL}/vector/downloads/vectoragent-latest"

NEW_SHA="$(sha256sum "$TMP_DIR/vectoragent" | awk '{print $1}')"
NEW_VERSION="$(release_version_for "$NEW_SHA")"
if [ -z "$NEW_VERSION" ]; then
    fail "The agent binary from ${SERVER_URL} is not a known rConfig Vector agent release (SHA256 ${NEW_SHA}). Nothing was installed."
fi
echo "Agent binary matches pinned release ${NEW_VERSION}."

CURRENT_SHA=""
CURRENT_VERSION=""
if [ -f "$BIN_PATH" ]; then
    CURRENT_SHA="$(sha256sum "$BIN_PATH" | awk '{print $1}')"
    CURRENT_VERSION="$(release_version_for "$CURRENT_SHA")"
    if [ -z "$CURRENT_VERSION" ]; then
        echo "Warning: the installed agent binary is not a known release; it will be replaced." >&2
    elif version_is_older "$NEW_VERSION" "$CURRENT_VERSION"; then
        fail "Refusing to downgrade the agent from ${CURRENT_VERSION} to ${NEW_VERSION}. Nothing was installed."
    fi
fi

parse_api_key() {
    if command -v python3 >/dev/null 2>&1; then
        python3 -c 'import json,sys; d=json.loads(sys.stdin.read()); print((d.get("data") or {}).get("api_token") or d.get("api_token") or "")' 2>/dev/null || true
        return
    fi
    if command -v jq >/dev/null 2>&1; then
        jq -r '.data.api_token // .api_token // empty' 2>/dev/null || true
        return
    fi
    sed -n 's/.*"api_token":"\([^"]*\)".*/\1/p'
}

API_KEY=""
if [ ! -f "$ENV_PATH" ]; then
    echo "Bootstrapping agent configuration..."
    BOOTSTRAP_RESPONSE="$(printf '{"agent_id":%s,"bootstrap_token":"%s"}' "$AGENT_ID" "$BOOTSTRAP_TOKEN" |
        curl "${CURL_OPTS[@]}" -X POST \
            -H 'Content-Type: application/json' \
            -H 'Accept: application/json' \
            --data-binary @- \
            "${SERVER_URL}/api/vector/agents/bootstrap")" || fail "The bootstrap request to ${SERVER_URL} failed. The token may be used or expired."

    API_KEY="$(printf '%s' "$BOOTSTRAP_RESPONSE" | parse_api_key)"
    [[ "$API_KEY" =~ ^[A-Za-z0-9-]{20,128}$ ]] || fail "The bootstrap response did not contain a valid API key. Nothing was installed."
fi

mkdir -p "$ACTIVE_DIR" "${DATA_DIR}/logs" "${DATA_DIR}/db" "${DATA_DIR}/files"

UPDATED_BINARY=0
if [ "$NEW_SHA" = "$CURRENT_SHA" ]; then
    echo "Agent ${NEW_VERSION} is already installed; skipping binary install."
else
    echo "Installing agent ${NEW_VERSION} to ${BIN_PATH}..."
    install -m 0755 "$TMP_DIR/vectoragent" "$BIN_PATH"
    UPDATED_BINARY=1
fi

if [ -n "$API_KEY" ]; then
    {
        echo "# This file is used to configure the rConfig Vector agent"
        echo "# Do not share this file with anyone"
        echo "# Do not commit this file to a GIT repository"
        echo "# Generated on $(date) by the rConfig Vector agent installer"
        echo ""
        echo "AGENT_DEBUG=false"
        echo "API_URL=${SERVER_URL}"
        echo "API_KEY=${API_KEY}"
        echo "SSL_VERIFY=${SSL_VERIFY}"
    } >"$ENV_PATH"
    chmod 600 "$ENV_PATH"
else
    echo "Existing .env detected; skipping bootstrap."
fi

setup_systemd_service() {
    if ! command -v systemctl >/dev/null 2>&1; then
        echo "systemctl not found; skipping service setup."
        return 0
    fi

    if [ ! -f "$UNIT_PATH" ]; then
        mkdir -p "$(dirname "$UNIT_PATH")"
        {
            echo "[Unit]"
            echo "Description=Vector Agent Service"
            echo "After=network.target"
            echo ""
            echo "[Service]"
            echo "ExecStart=${BIN_PATH}"
            echo "Restart=always"
            echo "User=root"
            echo "Group=root"
            echo "EnvironmentFile=${ENV_PATH}"
            echo ""
            echo "[Install]"
            echo "WantedBy=multi-user.target"
        } >"$UNIT_PATH"
        chmod 644 "$UNIT_PATH"
    fi

    systemctl daemon-reload || true
    systemctl enable vectoragent || true

    if [ "$UPDATED_BINARY" -eq 1 ]; then
        systemctl restart vectoragent || true
    else
        systemctl start vectoragent || true
    fi

    systemctl status vectoragent --no-pager || true
}

setup_systemd_service

"$BIN_PATH" --version || true

echo "Vector agent installation complete."
