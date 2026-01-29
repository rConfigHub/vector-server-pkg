#!/usr/bin/env bash
set -euo pipefail

AGENT_ID="{{ $agent->id }}"
BOOTSTRAP_TOKEN="{{ $bootstrapToken }}"
SERVER_URL="{{ $serverUrl }}"
DOWNLOAD_URL="${SERVER_URL}/vector/downloads/vectoragent-latest"
SHA_URL="${SERVER_URL}/vector/downloads/vectoragent-latest.sha256"
SSL_VERIFY="{{ $agent->ssl_verify ? 'true' : 'false' }}"

BASE_DIR="/usr/local/bin/rconfig"
ACTIVE_DIR="${BASE_DIR}/activeagent"
DATA_DIR="${BASE_DIR}/data"
LOG_DIR="${DATA_DIR}/logs"
DB_DIR="${DATA_DIR}/db"
FILES_DIR="${DATA_DIR}/files"
BIN_PATH="${ACTIVE_DIR}/vectoragent"
ENV_PATH="${ACTIVE_DIR}/.env"
TMP_DIR="$(mktemp -d)"
CURL_OPTS="-fsSL"
if [ "$SSL_VERIFY" = "false" ]; then
    CURL_OPTS="-kfsSL"
fi

cleanup() {
    rm -rf "$TMP_DIR"
}
trap cleanup EXIT

if ! command -v curl >/dev/null 2>&1; then
    MISSING_DEPS=1
fi

if ! command -v sha256sum >/dev/null 2>&1; then
    MISSING_DEPS=1
fi

if [ -n "${MISSING_DEPS:-}" ]; then
    if [ "$(id -u)" -ne 0 ]; then
        echo "Required dependencies missing. Please run as root to install curl/coreutils." >&2
        exit 1
    fi

    if command -v apt-get >/dev/null 2>&1; then
        apt-get update -y
        apt-get install -y curl coreutils
    elif command -v dnf >/dev/null 2>&1; then
        dnf install -y curl coreutils
    elif command -v yum >/dev/null 2>&1; then
        yum install -y curl coreutils
    else
        echo "No supported package manager found (apt, dnf, yum). Install curl and coreutils manually." >&2
        exit 1
    fi
fi

mkdir -p "$BASE_DIR" "$ACTIVE_DIR" "$LOG_DIR" "$DB_DIR" "$FILES_DIR"

echo "Downloading Vector agent binary..."
curl $CURL_OPTS "$DOWNLOAD_URL" -o "$TMP_DIR/vectoragent"

echo "Downloading checksum..."
curl $CURL_OPTS "$SHA_URL" -o "$TMP_DIR/vectoragent.sha256"

REMOTE_SHA="$(awk '{print $1}' "$TMP_DIR/vectoragent.sha256")"
LOCAL_SHA="$(sha256sum "$TMP_DIR/vectoragent" | awk '{print $1}')"

if [ -z "$REMOTE_SHA" ] || [ "$REMOTE_SHA" != "$LOCAL_SHA" ]; then
    echo "Checksum verification failed." >&2
    exit 1
fi

CURRENT_SHA=""
if [ -f "$BIN_PATH" ]; then
    CURRENT_SHA="$(sha256sum "$BIN_PATH" | awk '{print $1}')"
fi

UPDATED_BINARY=0
if [ "$REMOTE_SHA" = "$CURRENT_SHA" ] && [ -n "$CURRENT_SHA" ]; then
    echo "Binary already up to date; skipping install."
else
    echo "Installing binary to $BIN_PATH..."
    install -m 0755 "$TMP_DIR/vectoragent" "$BIN_PATH"
    UPDATED_BINARY=1
fi

if [ ! -f "$ENV_PATH" ]; then
    echo "Bootstrapping agent configuration..."
BOOTSTRAP_RESPONSE="$(curl $CURL_OPTS -X POST "${SERVER_URL}/api/vector/agents/bootstrap" \
        -H "Content-Type: application/json" \
        -d "{\"agent_id\":${AGENT_ID},\"bootstrap_token\":\"${BOOTSTRAP_TOKEN}\"}")"

    parse_api_key() {
        if command -v python3 >/dev/null 2>&1; then
            python3 -c 'import json,sys; d=json.loads(sys.stdin.read()); print(d.get("data",{}).get("api_token","") or d.get("api_token",""))' 2>/dev/null
            return
        fi
        if command -v python >/dev/null 2>&1; then
            python -c 'import json,sys; d=json.loads(sys.stdin.read()); print(d.get("data",{}).get("api_token","") or d.get("api_token",""))' 2>/dev/null
            return
        fi
        if command -v jq >/dev/null 2>&1; then
            jq -r '.data.api_token // .api_token // empty' 2>/dev/null
            return
        fi
        sed -n 's/.*"api_token":"\([^"]*\)".*/\1/p'
    }

    API_KEY="$(printf '%s' "$BOOTSTRAP_RESPONSE" | parse_api_key)"

    if [ -z "$API_KEY" ]; then
        echo "Failed to obtain API key from bootstrap response." >&2
        echo "Bootstrap response: $BOOTSTRAP_RESPONSE" >&2
        exit 1
    fi

    cat > "$ENV_PATH" <<EOF
# This file is used to configure the rConfig Vector agent
# Do not share this file with anyone
# Do not commit this file to a GIT repository
# Generated on $(date) by Vector Server

AGENT_DEBUG=false
API_URL=${SERVER_URL}
API_KEY=${API_KEY}
SSL_VERIFY=${SSL_VERIFY}
EOF

    chmod 600 "$ENV_PATH"
else
    echo "Existing .env detected; skipping bootstrap."
fi

setup_systemd_service() {
    if ! command -v systemctl >/dev/null 2>&1; then
        echo "systemctl not found; skipping service setup."
        return 0
    fi

    if [ ! -f /etc/systemd/system/vectoragent.service ]; then
        cat > /etc/systemd/system/vectoragent.service <<EOF
[Unit]
Description=Vector Agent Service
After=network.target

[Service]
ExecStart=${BIN_PATH}
Restart=always
User=root
Group=root
EnvironmentFile=${ENV_PATH}

[Install]
WantedBy=multi-user.target
EOF
    fi

    systemctl daemon-reload || true
    systemctl enable vectoragent || true

    if [ "$UPDATED_BINARY" -eq 1 ]; then
        systemctl restart vectoragent || true
    else
        systemctl start vectoragent || true
    fi
}

setup_systemd_service

if [ -x "$BIN_PATH" ]; then
    "$BIN_PATH" --version || true
fi

cd "$ACTIVE_DIR"

if command -v systemctl >/dev/null 2>&1; then
    systemctl status vectoragent --no-pager || true
fi

echo "Vector agent installation complete."
