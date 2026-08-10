<?php

namespace Rconfig\VectorServer\Services;

/**
 * Records an interactive `vector:ssh` session (RCO-1155) to a transcript file
 * when the operator opts in with --log. Writes a metadata header (which device
 * and agent, which operator, when) followed by the rendered session stream.
 *
 * Only the device->terminal output is captured — never operator keystrokes —
 * so credentials typed at hidden prompts (the device suppresses echo) are not
 * recorded. The resolved device password/key is never passed here at all.
 */
class SshSessionLogger
{
    /** @var resource|null */
    private $handle;

    private string $path;

    /**
     * @param  array{operator?:string, agent_id:int|string, agent_name?:?string, device_id:int|string, device_name:string, device_ip:string, device_port:int|string, username:string}  $meta
     */
    public function __construct(array $meta, ?string $dir = null)
    {
        $dir = rtrim($dir ?? storage_path('logs/vector-ssh'), '/');
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }

        // Collapse anything that is not a plain filename character (dots
        // included) so a device name can never inject path separators or "..".
        $safeName = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) ($meta['device_name'] ?? 'device'));
        $safeName = trim($safeName, '_') ?: 'device';
        $this->path = sprintf(
            '%s/%s_dev%d_%s_agent%s.log',
            $dir,
            now()->format('Ymd-His'),
            (int) $meta['device_id'],
            $safeName,
            (string) $meta['agent_id'],
        );

        $this->handle = fopen($this->path, 'wb');
        $this->writeHeader($meta);
    }

    public function path(): string
    {
        return $this->path;
    }

    /** Append a chunk of the rendered session output. */
    public function append(string $bytes): void
    {
        if (is_resource($this->handle) && $bytes !== '') {
            fwrite($this->handle, $bytes);
        }
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fwrite($this->handle, "\n# ---- session ended: " . now()->toIso8601String() . " ----\n");
            fclose($this->handle);
            $this->handle = null;
        }
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function writeHeader(array $meta): void
    {
        if (! is_resource($this->handle)) {
            return;
        }

        $header = [
            '# rConfig Vector SSH session transcript',
            '# started_at:   ' . now()->toIso8601String(),
            '# operator:     ' . ($meta['operator'] ?? 'unknown'),
            '# agent:        ' . $meta['agent_id'] . ' ' . ($meta['agent_name'] ?? ''),
            '# device:       ' . $meta['device_id'] . ' ' . $meta['device_name'] . ' (' . $meta['device_ip'] . ':' . $meta['device_port'] . ')',
            '# ssh_username: ' . $meta['username'],
            '# note:         credentials are never recorded; operator keystrokes are not captured',
            '# ---- session begins ----',
            '',
        ];

        fwrite($this->handle, implode("\n", $header));
    }
}
