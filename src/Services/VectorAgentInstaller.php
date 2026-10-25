<?php

namespace Rconfig\VectorServer\Services;

/**
 * The static Vector agent installer and the command that runs it (RCO-1817).
 *
 * The installer is served byte for byte, never rendered. The command
 * downloads it, checks its SHA256 and only then runs it as root, so a
 * changed installer stops before it runs. The SHA256 shown here is computed
 * by this server, which is why the UI and API point admins at the docs to
 * check it: the published value is the one the web user cannot change.
 */
class VectorAgentInstaller
{
    public const FILE_NAME = 'vector-agent-install.sh';

    public const DOCS_URL = 'https://docs.rconfig.com/installation-upgrades/vector/agent-installation/#installer-sha256';

    public function scriptPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/scripts/' . self::FILE_NAME;
    }

    public function retiredScriptPath(): string
    {
        return dirname(__DIR__, 2) . '/resources/scripts/vector-agent-install-retired.sh';
    }

    public function sha256(): string
    {
        return (string) hash_file('sha256', $this->scriptPath());
    }

    /**
     * The command to paste on the agent host. TLS checks stay on unless the
     * agent is set to skip them for a self-signed rConfig certificate.
     */
    public function command(string $host, int $agentId, string $bootstrapToken, bool $sslVerify = true): string
    {
        $serverUrl = 'https://' . $host;
        $insecure = $sslVerify ? '' : ' -k';
        $file = self::FILE_NAME;

        return "curl -fsSL{$insecure} --proto '=https' -o {$file} " . escapeshellarg($serverUrl . '/vector/agent-install.sh')
            . " && echo '{$this->sha256()}  {$file}' | sha256sum -c -"
            . " && sudo bash {$file} --server " . escapeshellarg($serverUrl)
            . ' --agent-id ' . $agentId
            . ' --token ' . escapeshellarg($bootstrapToken)
            . ($sslVerify ? '' : ' --insecure-tls');
    }

    /**
     * The install fields every install-command response carries.
     *
     * @return array{command: string, installer_sha256: string, installer_docs_url: string}
     */
    public function payload(string $host, int $agentId, string $bootstrapToken, bool $sslVerify = true): array
    {
        return [
            'command' => $this->command($host, $agentId, $bootstrapToken, $sslVerify),
            'installer_sha256' => $this->sha256(),
            'installer_docs_url' => self::DOCS_URL,
        ];
    }
}
