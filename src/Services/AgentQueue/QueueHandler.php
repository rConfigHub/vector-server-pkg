<?php

namespace  Rconfig\VectorServer\Services\AgentQueue;

use App\Models\Template;
use App\Services\Connections\Params\DeviceParams;
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
        // Use DeviceParams to properly resolve device_credentials for the device, if present
        $deviceParams = new DeviceParams($device);
        $resolvedParams = $deviceParams->getAllDeviceParams();
        $deviceRecord = $resolvedParams->deviceparams;

        // Initial connection parameters
        $connection_params = [
            'username' => $deviceRecord['device_username'] ?? '',
            'password' => $deviceRecord['device_password'] ?? '',
            'enable_password' => $deviceRecord['device_enable_password'] ?? '',
            'main_prompt' => $deviceRecord['device_main_prompt'] ?? '',
            'enable_prompt' => $deviceRecord['device_enable_prompt'] ?? '',
            'command' => $command ?? '',
        ];

        // Connect params
        $connection_params['timeout'] = isset($yamlContents['connect']['timeout']) ? $yamlContents['connect']['timeout'] : 30;
        $connection_params['retry_count'] = isset($yamlContents['connect']['retry_count']) ? $yamlContents['connect']['retry_count'] : 3;
        $connection_params['protocol'] = isset($yamlContents['connect']['protocol']) ? $yamlContents['connect']['protocol'] : 'ssh-agent';
        $connection_params['port'] = isset($yamlContents['connect']['port']) ? (string)$yamlContents['connect']['port'] : '22';
        $connection_params['retries'] = isset($yamlContents['connect']['retries']) ? $yamlContents['connect']['retries'] : 3;
        $connection_params['isNonInteractiveMode'] = isset($yamlContents['connect']['isNonInteractiveMode']) ? $yamlContents['connect']['isNonInteractiveMode'] : false;
        $connection_params['ctrlYLogin'] = isset($yamlContents['connect']['ctrlYLogin']) && $yamlContents['connect']['ctrlYLogin'] === 'on' ? 'on' : 'off';

        // Auth params
        $connection_params['usernamePrompt'] = isset($yamlContents['auth']['username']) ? $yamlContents['auth']['username'] : '';
        $connection_params['passwordPrompt'] = isset($yamlContents['auth']['password']) ? $yamlContents['auth']['password'] : '';
        $connection_params['enable'] = isset($yamlContents['auth']['enable']) && $yamlContents['auth']['enable'] === 'on' ? 'on' : 'off';
        $connection_params['enableCmd'] = isset($yamlContents['auth']['enableCmd']) ? $yamlContents['auth']['enableCmd'] : '';
        $connection_params['enablePassPrmpt'] = isset($yamlContents['auth']['enablePassPrmpt']) ? $yamlContents['auth']['enablePassPrmpt'] : '';
        $connection_params['hpAnyKeyStatus'] = isset($yamlContents['auth']['hpAnyKeyStatus']) && $yamlContents['auth']['hpAnyKeyStatus'] === 'on' ? 'on' : 'off';
        $connection_params['hpAnyKeyPrmpt'] = isset($yamlContents['auth']['hpAnyKeyPrmpt']) ? $yamlContents['auth']['hpAnyKeyPrmpt'] : '';

        // Config params
        $connection_params['linebreak'] = isset($yamlContents['config']['linebreak']) ? $yamlContents['config']['linebreak'] : 'n';
        $connection_params['paging'] = isset($yamlContents['config']['paging']) && $yamlContents['config']['paging'] === 'on' ? 'on' : 'off';
        $connection_params['pagingCmd'] = isset($yamlContents['config']['pagingCmd']) ? $yamlContents['config']['pagingCmd'] : '';
        $connection_params['resetPagingCmd'] = isset($yamlContents['config']['resetPagingCmd']) ? $yamlContents['config']['resetPagingCmd'] : '';
        $connection_params['pagerPrompt'] = isset($yamlContents['config']['pagerPrompt']) ? $yamlContents['config']['pagerPrompt'] : '';
        $connection_params['pagerPromptCmd'] = isset($yamlContents['config']['pagerPromptCmd']) ? $yamlContents['config']['pagerPromptCmd'] : '';
        $connection_params['saveConfig'] = isset($yamlContents['config']['saveConfig']) ? $yamlContents['config']['saveConfig'] : '';
        $connection_params['exitCmd'] = isset($yamlContents['config']['exitCmd']) ? $yamlContents['config']['exitCmd'] : '';

        // Main params
        $connection_params['name'] = isset($yamlContents['main']['name']) ? $yamlContents['main']['name'] : '';
        $connection_params['desc'] = isset($yamlContents['main']['desc']) ? $yamlContents['main']['desc'] : '';

        return $connection_params;
    }
}
