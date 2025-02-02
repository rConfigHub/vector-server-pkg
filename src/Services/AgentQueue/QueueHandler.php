<?php

namespace  Rconfig\VectorServer\Services\AgentQueue;

use App\Models\Template;
use Illuminate\Support\Str;
use Rconfig\VectorServer\Models\Agent;
use Rconfig\VectorServer\Models\AgentQueue;
use Symfony\Component\Yaml\Yaml;

class QueueHandler
{
    public function create_from_device(array $device)
    {
        try {

            $template = Template::where('id', $device['device_template'])->first();
            $fileContents = file_get_contents(storage_path() . $template->fileName);
            $yamlContents = Yaml::parse($fileContents);

            foreach ($device['commands'] as $command) {

                // convert $device['device_enable_password'] to emptry string if bool or null
                if (is_bool($device['device_enable_password']) || is_null($device['device_enable_password'])) {
                    $device['device_enable_password'] = '';
                }

                $connection_params = $this->buildConnectionParams($yamlContents, $device, $command);

                AgentQueue::create([
                    'agent_id' => $device['agent_id'],
                    'ulid' => Str::ulid()->toBase32(),
                    'device_id' => $device['id'],
                    'ip_address' => $device['device_ip'],
                    'connection_params' => $connection_params,
                    'retry_attempt' => $yamlContents['connect']['retries'] ?? 1,
                ]);
            }
        } catch (\Exception $e) {
            $logmsg =  'Error creating agent queue record for device ID: ' . $device['id'] . ' ' . $e->getMessage();
            activityLogIt(__CLASS__, __FUNCTION__, 'info', $logmsg, 'connection', '', '', 'agnet_queue', $device['id']);
            return false;
        }

        return true;
    }

    function buildConnectionParams(array $yamlContents, array $device, string $command): array
    {
        // Initial connection parameters
        $connection_params = [
            'username' => $device['device_username'] ?? '',
            'password' => $device['device_password'] ?? '',
            'enable_password' => $device['device_enable_password'] ?? '',
            'main_prompt' =>  $device['device_main_prompt'] ?? '',
            'enable_prompt' => $device['device_enable_prompt'] ?? '',
            'command' => $command ?? '',
        ];

        // Explicitly map second-level keys to $connection_params
        $connection_params['timeout'] = $yamlContents['connect']['timeout'] ?? 30;
        $connection_params['retry_count'] = $yamlContents['connect']['retry_count'] ?? 3;
        $connection_params['protocol'] = $yamlContents['connect']['protocol'] ?? 'ssh-agent';
        $connection_params['port'] = (string)($yamlContents['connect']['port'] ?? '22');
        $connection_params['retries'] = $yamlContents['connect']['retries'] ?? 3;
        $connection_params['isNonInteractiveMode'] = $yamlContents['connect']['isNonInteractiveMode'] ?? false;
        $connection_params['usernamePrompt'] = $yamlContents['auth']['username'] ?? '';
        $connection_params['passwordPrompt'] = $yamlContents['auth']['password'] ?? '';
        $connection_params['enable'] = $yamlContents['auth']['enable'] ?? 'off';
        $connection_params['enableCmd'] = $yamlContents['auth']['enableCmd'] ?? '';
        $connection_params['enablePassPrmpt'] = $yamlContents['auth']['enablePassPrmpt'] ?? '';
        $connection_params['hpAnyKeyStatus'] = $yamlContents['auth']['hpAnyKeyStatus'] ?? 'off';
        $connection_params['hpAnyKeyPrmpt'] = $yamlContents['auth']['hpAnyKeyPrmpt'] ?? '';
        $connection_params['linebreak'] = $yamlContents['config']['linebreak'] ?? 'n';
        $connection_params['paging'] = $yamlContents['config']['paging'] ?? 'off';
        $connection_params['pagingCmd'] = $yamlContents['config']['pagingCmd'] ?? '';
        $connection_params['resetPagingCmd'] = $yamlContents['config']['resetPagingCmd'] ?? '';
        $connection_params['pagerPrompt'] = $yamlContents['config']['pagerPrompt'] ?? '';
        $connection_params['pagerPromptCmd'] = $yamlContents['config']['pagerPromptCmd'] ?? '';
        $connection_params['saveConfig'] = $yamlContents['config']['saveConfig'] ?? '';
        $connection_params['exitCmd'] = $yamlContents['config']['exitCmd'] ?? '';
        $connection_params['name'] = $yamlContents['main']['name'] ?? '';
        $connection_params['desc'] = $yamlContents['main']['desc'] ?? '';

        return $connection_params;
    }
}
