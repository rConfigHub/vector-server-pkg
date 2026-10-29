#!/usr/bin/env bash
set -euo pipefail

# =============================================================================
# rConfig Vector Hub installer (RCO-744 live channel)
#
# UNLIKE the agent installer, the hub is ALWAYS installed on the rConfig server
# itself, co-located with the Laravel app. That lets this script provision both
# sides at once: it generates the shared secret + mTLS material once and writes
# the hub env AND the rConfig .env, so no token exchange over the network is
# needed. Run it as root ON THE RCONFIG SERVER.
#
# Site-specific values are runtime flags (see --help). The Blade layer fills
# sensible defaults from the running app; flags override them.
# =============================================================================

# ---- defaults (Blade-injected where the app knows them; env as fallback) ----
RCONFIG_ROOT="${RCONFIG_ROOT:-{{ $rconfigRoot ?? '/var/www/html/rconfig8' }}}"
# Base URL Laravel is reachable at for the hub's auth callback. MUST match the
# name on rConfig's TLS cert — defaulting to the app URL is what avoids the
# "127.0.0.1 has no IP SAN" failure. Override with --rconfig-url.
RCONFIG_URL="${RCONFIG_URL:-{{ $rconfigUrl ?? config('app.url') }}}"
# CA that signs rConfig's web cert; only needed when it is self-signed/internal
# so the hub can trust the auth callback. Empty => use the system trust store.
AUTH_CA="${AUTH_CA:-}"
# Public host agents dial for the WSS tunnel. Empty => machine FQDN at runtime.
TUNNEL_HOST="${TUNNEL_HOST:-{{ $tunnelHost ?? '' }}}"
# Operator-supplied tunnel cert/key (a real cert for TUNNEL_HOST that agents
# already trust). Given together; when set, the self-signed tunnel cert is not
# generated. Empty => fall back to the internal-CA self-signed tunnel cert.
TUNNEL_CERT_SRC="${TUNNEL_CERT_SRC:-}"
TUNNEL_KEY_SRC="${TUNNEL_KEY_SRC:-}"
HUB_BIN="${HUB_BIN:-}"                           # local prebuilt file; skips download
DOWNLOAD_INSECURE="${DOWNLOAD_INSECURE:-false}"  # curl -k for the binary fetch
UNINSTALL="${UNINSTALL:-false}"                  # stop + completely remove the hub
TUNNEL_ADDR="${TUNNEL_ADDR:-:8443}"              # agent-facing WSS listener
API_ADDR="${API_ADDR:-127.0.0.1:8444}"           # Laravel-facing loopback mTLS API
SESSION_ADDR="${SESSION_ADDR:-127.0.0.1:8445}"   # loopback session broker for `vector:ssh` (RCO-1155)
TLS_DIR="${TLS_DIR:-/etc/vector-hub/tls}"

usage() {
    cat <<'USAGE'
Usage: install-hub.sh [options]

  --rconfig-url URL     Base URL Laravel is reachable at for the hub auth
                        callback; must match rConfig's TLS cert name. This is
                        the fix for the 127.0.0.1 IP-SAN failure. (default: app URL)
  --auth-ca PATH        CA file to trust for the auth callback, when rConfig's
                        web cert is self-signed/internal. (default: system trust)
  --tunnel-host HOST    Public host agents dial for the WSS tunnel. (default: FQDN)
  --tunnel-cert PATH    Real TLS cert for the tunnel host that agents already
                        trust. Use with --tunnel-key to avoid the self-signed
                        cert (recommended in production). (default: self-signed)
  --tunnel-key PATH     Private key for --tunnel-cert.
  --hub-bin PATH        Install a local prebuilt binary instead of downloading
                        from the fixed release URL.
  --rconfig-root PATH   rConfig app root. (default: /var/www/html/rconfig8)
  --tunnel-addr ADDR    Tunnel listener address. (default: :8443)
  --api-addr ADDR       Loopback mTLS API address. (default: 127.0.0.1:8444)
  --session-addr ADDR   Loopback session-broker address for interactive SSH
                        (vector:ssh). (default: 127.0.0.1:8445)
  --tls-dir PATH        Where hub TLS material is written/read. (default: /etc/vector-hub/tls)
  --insecure-download   Skip TLS verification for the binary download only.
  --uninstall           Stop and completely remove the hub (service, binary,
                        certs, config, service account) and clear its rConfig
                        .env keys. Ignores all other flags.
  -h, --help            Show this help and exit.
USAGE
}

# ---- argument parsing (flags override the defaults above) -------------------
while [ $# -gt 0 ]; do
    case "$1" in
        --rconfig-url)      RCONFIG_URL="${2:?--rconfig-url needs a value}"; shift 2;;
        --auth-ca)          AUTH_CA="${2:?--auth-ca needs a value}"; shift 2;;
        --tunnel-host)      TUNNEL_HOST="${2:?--tunnel-host needs a value}"; shift 2;;
        --tunnel-cert)      TUNNEL_CERT_SRC="${2:?--tunnel-cert needs a value}"; shift 2;;
        --tunnel-key)       TUNNEL_KEY_SRC="${2:?--tunnel-key needs a value}"; shift 2;;
        --hub-bin)          HUB_BIN="${2:?--hub-bin needs a value}"; shift 2;;
        --rconfig-root)     RCONFIG_ROOT="${2:?--rconfig-root needs a value}"; shift 2;;
        --tunnel-addr)      TUNNEL_ADDR="${2:?--tunnel-addr needs a value}"; shift 2;;
        --api-addr)         API_ADDR="${2:?--api-addr needs a value}"; shift 2;;
        --session-addr)     SESSION_ADDR="${2:?--session-addr needs a value}"; shift 2;;
        --tls-dir)          TLS_DIR="${2:?--tls-dir needs a value}"; shift 2;;
        --insecure-download) DOWNLOAD_INSECURE=true; shift;;
        --uninstall)        UNINSTALL=true; shift;;
        -h|--help)          usage; exit 0;;
        *) echo "Unknown option: $1" >&2; usage >&2; exit 1;;
    esac
done

# ---- fixed paths & release location -----------------------------------------
# Mirror the agent installer's layout: BASE_DIR/active<component>/<binary>
# (agent uses /usr/local/bin/rconfig/activeagent/vectoragent).
HUB_BASE_DIR="/usr/local/bin/rconfig"
BIN_PATH="${HUB_BASE_DIR}/activehub/vector-hub"
CONF_DIR="/etc/vector-hub"
ENV_PATH="${CONF_DIR}/vector-hub.env"
UNIT_PATH="/etc/systemd/system/vector-hub.service"
HUB_USER="vector-hub"
HUB_GROUP="vector-hub"

# Static release location for the prebuilt hub binary (not customer-specific).
# --hub-bin overrides this for local/dev installs.
HUB_DOWNLOAD_URL="https://dl.rconfig.com/download-vector-hub/vector-hub-latest"
HUB_SHA_URL="${HUB_DOWNLOAD_URL}.sha256"

if [ "$(id -u)" -ne 0 ]; then
    echo "This installer must run as root on the rConfig server." >&2
    exit 1
fi

# ---- uninstall mode: stop + completely remove the hub, then exit ------------
if [ "$UNINSTALL" = "true" ]; then
    RCONFIG_ENV="${RCONFIG_ROOT}/.env"
    LARAVEL_TLS_DIR="${RCONFIG_ROOT}/storage/app/vector-hub"

    echo "Stopping and removing Vector Hub ..."
    if command -v systemctl >/dev/null 2>&1; then
        systemctl stop vector-hub 2>/dev/null || true
        systemctl disable vector-hub 2>/dev/null || true
    fi
    rm -f "$UNIT_PATH"
    command -v systemctl >/dev/null 2>&1 && systemctl daemon-reload || true

    rm -f "$BIN_PATH"
    rmdir "$(dirname "$BIN_PATH")" 2>/dev/null || true   # activehub/, only if empty
    rm -rf "$CONF_DIR"                                    # env + TLS material
    rm -rf "$LARAVEL_TLS_DIR"                             # Laravel client certs + temp scripts

    # Strip the hub keys from rConfig's .env so it no longer reports configured.
    if [ -f "$RCONFIG_ENV" ]; then
        for key in VECTOR_HUB_TUNNEL_URL VECTOR_HUB_API_URL VECTOR_HUB_SESSION_ADDR \
                   VECTOR_HUB_AUTH_SECRET VECTOR_HUB_CLIENT_CERT VECTOR_HUB_CLIENT_KEY VECTOR_HUB_CA_CERT; do
            sed -i "/^${key}=/d" "$RCONFIG_ENV"
        done
        PHP_BIN="$(command -v php || true)"
        if [ -n "$PHP_BIN" ] && [ -f "${RCONFIG_ROOT}/artisan" ]; then
            WEB_USER="$(stat -c '%U' "$RCONFIG_ENV" 2>/dev/null || echo apache)"
            ( cd "$RCONFIG_ROOT" && sudo -u "$WEB_USER" "$PHP_BIN" artisan config:clear >/dev/null 2>&1 ) || true
        fi
    fi

    # Remove the dedicated service account.
    if getent passwd "$HUB_USER" >/dev/null 2>&1; then
        userdel "$HUB_USER" 2>/dev/null || true
    fi
    if getent group "$HUB_GROUP" >/dev/null 2>&1; then
        groupdel "$HUB_GROUP" 2>/dev/null || true
    fi

    echo "Vector Hub uninstalled."
    exit 0
fi

# ---- derive values (after flags are known) ----------------------------------
if [ -z "$TUNNEL_HOST" ]; then
    TUNNEL_HOST="$(hostname -f 2>/dev/null || hostname)"
fi
if [ -z "$RCONFIG_URL" ]; then
    echo "No rConfig URL known; pass --rconfig-url https://<fqdn-matching-cert>." >&2
    exit 1
fi
if [ -n "$TUNNEL_CERT_SRC" ] || [ -n "$TUNNEL_KEY_SRC" ]; then
    if [ -z "$TUNNEL_CERT_SRC" ] || [ -z "$TUNNEL_KEY_SRC" ]; then
        echo "--tunnel-cert and --tunnel-key must be given together." >&2
        exit 1
    fi
    [ -f "$TUNNEL_CERT_SRC" ] || { echo "--tunnel-cert not found: $TUNNEL_CERT_SRC" >&2; exit 1; }
    [ -f "$TUNNEL_KEY_SRC" ] || { echo "--tunnel-key not found: $TUNNEL_KEY_SRC" >&2; exit 1; }
fi
API_PORT="${API_ADDR##*:}"
TUNNEL_URL="wss://${TUNNEL_HOST}${TUNNEL_ADDR}/tunnel"
API_URL="https://127.0.0.1:${API_PORT}"
AUTH_URL="${RCONFIG_URL%/}/api/vector-hub/authenticate-agent"

# Where Laravel (the web user) reads its client cert from — must be readable by
# the web process, so it lives under storage, not in root-only /etc/vector-hub.
LARAVEL_TLS_DIR="${RCONFIG_ROOT}/storage/app/vector-hub"

# ---- dependencies -----------------------------------------------------------
need() { command -v "$1" >/dev/null 2>&1; }

install_pkgs() {
    if need apt-get; then apt-get update -y && apt-get install -y "$@"
    elif need dnf; then dnf install -y "$@"
    elif need yum; then yum install -y "$@"
    else
        echo "No supported package manager (apt/dnf/yum); install manually: $*" >&2
        exit 1
    fi
}

MISSING=()
need openssl || MISSING+=(openssl)
if [ -z "$HUB_BIN" ]; then
    need curl || MISSING+=(curl)
    need sha256sum || MISSING+=(coreutils)
fi
[ ${#MISSING[@]} -gt 0 ] && install_pkgs "${MISSING[@]}"

if ! need systemctl; then
    echo "systemctl not found; this installer targets systemd hosts." >&2
    exit 1
fi

# ---- service account --------------------------------------------------------
if ! getent group "$HUB_GROUP" >/dev/null 2>&1; then
    groupadd --system "$HUB_GROUP"
fi
if ! getent passwd "$HUB_USER" >/dev/null 2>&1; then
    useradd --system --no-create-home --shell /usr/sbin/nologin \
        --gid "$HUB_GROUP" "$HUB_USER"
fi

mkdir -p "$CONF_DIR" "$TLS_DIR" "$(dirname "$BIN_PATH")"
chmod 0755 "$CONF_DIR"

# ---- binary: download a prebuilt executable (or install a local file) --------
install_binary() {
    # Dev convenience: install a local prebuilt and skip the download entirely.
    if [ -n "$HUB_BIN" ]; then
        [ -f "$HUB_BIN" ] || { echo "HUB_BIN=$HUB_BIN not found." >&2; exit 1; }
        echo "Installing local hub binary from $HUB_BIN ..."
        install -m 0755 "$HUB_BIN" "$BIN_PATH"
        return
    fi

    if [ -z "$HUB_DOWNLOAD_URL" ]; then
        echo "Release URL is unset and no --hub-bin provided." >&2
        exit 1
    fi

    local curl_opts="-fsSL"
    [ "$DOWNLOAD_INSECURE" = "true" ] && curl_opts="-kfsSL"

    local tmp; tmp="$(mktemp -d)"
    trap 'rm -rf "$tmp"' RETURN

    echo "Downloading hub binary from $HUB_DOWNLOAD_URL ..."
    curl $curl_opts "$HUB_DOWNLOAD_URL" -o "$tmp/vector-hub"

    echo "Downloading checksum from $HUB_SHA_URL ..."
    curl $curl_opts "$HUB_SHA_URL" -o "$tmp/vector-hub.sha256"

    local remote_sha local_sha
    remote_sha="$(awk '{print $1}' "$tmp/vector-hub.sha256")"
    local_sha="$(sha256sum "$tmp/vector-hub" | awk '{print $1}')"
    if [ -z "$remote_sha" ] || [ "$remote_sha" != "$local_sha" ]; then
        echo "Checksum verification failed (expected $remote_sha, got $local_sha)." >&2
        exit 1
    fi

    # Skip the install (and needless restart) when the binary is unchanged.
    if [ -f "$BIN_PATH" ] && [ "$remote_sha" = "$(sha256sum "$BIN_PATH" | awk '{print $1}')" ]; then
        echo "Hub binary already up to date; keeping installed copy."
        return
    fi
    install -m 0755 "$tmp/vector-hub" "$BIN_PATH"
}
install_binary
echo "Installed vector-hub -> $BIN_PATH"

# ---- shared secret: reuse the existing one, or mint a new one ----------------
# Re-runs must not rotate a live secret out from under a running deployment.
RCONFIG_ENV="${RCONFIG_ROOT}/.env"
[ -f "$RCONFIG_ENV" ] || { echo "rConfig .env not found at $RCONFIG_ENV." >&2; exit 1; }

read_env() { grep -E "^$1=" "$2" 2>/dev/null | head -n1 | cut -d= -f2- | tr -d '"'; }

# `|| true`: grep exits non-zero when the key is absent (fresh install), which
# would otherwise trip pipefail+set -e before we can mint a new secret.
AUTH_SECRET="$(read_env VECTOR_HUB_AUTH_SECRET "$RCONFIG_ENV" || true)"
if [ -z "$AUTH_SECRET" ]; then
    AUTH_SECRET="$(openssl rand -hex 32)"
    echo "Generated a new hub shared secret."
else
    echo "Reusing existing VECTOR_HUB_AUTH_SECRET from rConfig .env."
fi

# ---- internal PKI (idempotent: never clobber existing certs) -----------------
CA_CRT="${TLS_DIR}/ca.crt"
CA_KEY="${TLS_DIR}/ca.key"
API_CRT="${TLS_DIR}/api.crt"
API_KEY="${TLS_DIR}/api.key"
TUN_CRT="${TLS_DIR}/tunnel.crt"
TUN_KEY="${TLS_DIR}/tunnel.key"
CLI_CRT="${TLS_DIR}/laravel-client.crt"
CLI_KEY="${TLS_DIR}/laravel-client.key"

gen_ca() {
    [ -f "$CA_CRT" ] && return 0
    echo "Generating internal CA ..."
    openssl req -x509 -newkey rsa:4096 -nodes -days 3650 \
        -keyout "$CA_KEY" -out "$CA_CRT" \
        -subj "/O=rConfig/CN=Vector Hub Internal CA"
}

# gen_leaf <name> <key> <crt> <cn> <extfile-contents>
gen_leaf() {
    local name="$1" key="$2" crt="$3" cn="$4" ext="$5"
    [ -f "$crt" ] && { echo "Keeping existing ${name} cert."; return 0; }
    echo "Generating ${name} cert ..."
    local tmp; tmp="$(mktemp -d)"
    printf '%s\n' "$ext" > "$tmp/ext.cnf"
    openssl req -newkey rsa:2048 -nodes -keyout "$key" -out "$tmp/csr" \
        -subj "/O=rConfig/CN=${cn}"
    openssl x509 -req -in "$tmp/csr" -CA "$CA_CRT" -CAkey "$CA_KEY" \
        -CAcreateserial -days 825 -out "$crt" -extfile "$tmp/ext.cnf"
    rm -rf "$tmp"
}

gen_ca
gen_leaf "API server (loopback mTLS)" "$API_KEY" "$API_CRT" "vector-hub-api" \
    "subjectAltName=IP:127.0.0.1,DNS:localhost"
if [ -n "$TUNNEL_CERT_SRC" ]; then
    echo "Installing operator-provided tunnel cert (agents must already trust it)."
    install -m 0644 "$TUNNEL_CERT_SRC" "$TUN_CRT"
    install -m 0640 "$TUNNEL_KEY_SRC" "$TUN_KEY"
else
    gen_leaf "agent tunnel (WSS, self-signed)" "$TUN_KEY" "$TUN_CRT" "$TUNNEL_HOST" \
        "subjectAltName=DNS:${TUNNEL_HOST}"
fi
gen_leaf "Laravel API client" "$CLI_KEY" "$CLI_CRT" "rconfig-laravel" \
    "extendedKeyUsage=clientAuth"

# hub-owned TLS: private keys 0640 root:vector-hub, certs world-readable
chown -R "root:${HUB_GROUP}" "$TLS_DIR"
find "$TLS_DIR" -name '*.key' -exec chmod 0640 {} +
find "$TLS_DIR" -name '*.crt' -exec chmod 0644 {} +

# ---- hub env file -----------------------------------------------------------
umask 077
{
    cat <<EOF
# Vector Hub configuration — generated $(date) by the rConfig installer.
# Do not edit the secret/paths by hand; re-run the installer instead.

VECTOR_HUB_TUNNEL_ADDR=${TUNNEL_ADDR}
VECTOR_HUB_TUNNEL_CERT=${TUN_CRT}
VECTOR_HUB_TUNNEL_KEY=${TUN_KEY}

VECTOR_HUB_API_ADDR=${API_ADDR}
VECTOR_HUB_API_CERT=${API_CRT}
VECTOR_HUB_API_KEY=${API_KEY}
VECTOR_HUB_API_CLIENT_CA=${CA_CRT}

VECTOR_HUB_SESSION_ADDR=${SESSION_ADDR}

VECTOR_HUB_AUTH_URL=${AUTH_URL}
VECTOR_HUB_AUTH_SECRET=${AUTH_SECRET}
EOF
    [ -n "$AUTH_CA" ] && echo "VECTOR_HUB_AUTH_CA=${AUTH_CA}"
    cat <<EOF

VECTOR_HUB_HEARTBEAT_INTERVAL=15s
VECTOR_HUB_LOG_LEVEL=info
EOF
} > "$ENV_PATH"
chown "root:${HUB_GROUP}" "$ENV_PATH"
chmod 0640 "$ENV_PATH"
umask 022

# ---- Laravel-side wiring (client cert + rConfig .env) ------------------------
WEB_USER="$(stat -c '%U' "$RCONFIG_ENV" 2>/dev/null || echo apache)"
WEB_GROUP="$(stat -c '%G' "$RCONFIG_ENV" 2>/dev/null || echo apache)"

mkdir -p "$LARAVEL_TLS_DIR"
install -m 0640 -o "$WEB_USER" -g "$WEB_GROUP" "$CLI_CRT" "${LARAVEL_TLS_DIR}/client.crt"
install -m 0640 -o "$WEB_USER" -g "$WEB_GROUP" "$CLI_KEY" "${LARAVEL_TLS_DIR}/client.key"
install -m 0644 -o "$WEB_USER" -g "$WEB_GROUP" "$CA_CRT"  "${LARAVEL_TLS_DIR}/ca.crt"
chown "$WEB_USER:$WEB_GROUP" "$LARAVEL_TLS_DIR"
chmod 0750 "$LARAVEL_TLS_DIR"

# Upsert a KEY=VALUE into the rConfig .env (| delimiter — values are paths/hex).
set_env() {
    local key="$1" val="$2"
    if grep -qE "^${key}=" "$RCONFIG_ENV"; then
        sed -i "s|^${key}=.*|${key}=${val}|" "$RCONFIG_ENV"
    else
        printf '%s=%s\n' "$key" "$val" >> "$RCONFIG_ENV"
    fi
}

set_env VECTOR_HUB_TUNNEL_URL "$TUNNEL_URL"
set_env VECTOR_HUB_API_URL    "$API_URL"
set_env VECTOR_HUB_SESSION_ADDR "$SESSION_ADDR"
set_env VECTOR_HUB_AUTH_SECRET "$AUTH_SECRET"
set_env VECTOR_HUB_CLIENT_CERT "${LARAVEL_TLS_DIR}/client.crt"
set_env VECTOR_HUB_CLIENT_KEY  "${LARAVEL_TLS_DIR}/client.key"
set_env VECTOR_HUB_CA_CERT     "${LARAVEL_TLS_DIR}/ca.crt"

# Pick up the new env; refresh a cached config if one exists.
PHP_BIN="$(command -v php || true)"
if [ -n "$PHP_BIN" ] && [ -f "${RCONFIG_ROOT}/artisan" ]; then
    ( cd "$RCONFIG_ROOT" && sudo -u "$WEB_USER" "$PHP_BIN" artisan config:clear >/dev/null 2>&1 ) || true
fi

# ---- systemd unit -----------------------------------------------------------
cat > "$UNIT_PATH" <<EOF
[Unit]
Description=rConfig Vector Hub (live channel tunnel terminator)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=${HUB_USER}
Group=${HUB_GROUP}
EnvironmentFile=${ENV_PATH}
ExecStart=${BIN_PATH}
Restart=on-failure
RestartSec=5s
NoNewPrivileges=true
ProtectSystem=strict
ProtectHome=true
PrivateTmp=true
ReadOnlyPaths=${CONF_DIR}
CapabilityBoundingSet=
AmbientCapabilities=
RestrictAddressFamilies=AF_INET AF_INET6 AF_UNIX
LockPersonality=true
MemoryDenyWriteExecute=true

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable vector-hub >/dev/null 2>&1 || true
systemctl restart vector-hub

# ---- verify -----------------------------------------------------------------
sleep 1
if systemctl is-active --quiet vector-hub; then
    echo "vector-hub service is active."
else
    echo "vector-hub failed to start. Recent logs:" >&2
    journalctl -u vector-hub -n 30 --no-pager >&2 || true
    exit 1
fi

# Prove the loopback mTLS API answers with Laravel's client cert.
if need curl; then
    if curl -sf --cacert "$CA_CRT" --cert "$CLI_CRT" --key "$CLI_KEY" \
        "https://127.0.0.1:${API_PORT}/health" >/dev/null 2>&1; then
        echo "Hub control API health check passed (mTLS)."
    else
        echo "Warning: could not reach the hub control API over mTLS yet." >&2
    fi
fi

# Prove the hub can actually reach Laravel's auth callback (the check that the
# 127.0.0.1 default used to fail). A configured-but-tokenless call should get a
# 4xx from the endpoint, NOT a TLS/connection error.
if need curl; then
    cb_opts=(-s -o /dev/null -w '%{http_code}')
    [ -n "$AUTH_CA" ] && cb_opts+=(--cacert "$AUTH_CA")
    code="$(curl "${cb_opts[@]}" -X POST "$AUTH_URL" 2>/dev/null || echo 000)"
    if [ "$code" = "000" ]; then
        echo "Warning: hub auth callback URL ${AUTH_URL} is unreachable or fails TLS." >&2
        echo "         Pass --rconfig-url <fqdn-matching-cert> and/or --auth-ca <ca>." >&2
    else
        echo "Auth callback endpoint reachable (HTTP ${code})."
    fi
fi

cat <<EOF

Vector Hub installation complete.

  Binary          ${BIN_PATH}
  Config          ${ENV_PATH}
  TLS material    ${TLS_DIR}
  Agent tunnel    ${TUNNEL_URL}
  Control API     ${API_URL} (loopback, mTLS)
  Auth callback   ${AUTH_URL}

Next steps:
  * Confirm agents can resolve/reach ${TUNNEL_HOST} on the tunnel port (${TUNNEL_ADDR}).
  * Enable the live channel per-agent in the rConfig UI (default OFF).
EOF
if [ -z "$TUNNEL_CERT_SRC" ]; then
    cat <<EOF
  * The self-signed tunnel cert is signed by the internal CA at ${CA_CRT};
    either distribute that CA to agents or set the agent's SSLVerify=false.
EOF
else
    echo "  * Tunnel uses your provided cert (${TUN_CRT}); agents that already trust it connect with SSLVerify on."
fi
