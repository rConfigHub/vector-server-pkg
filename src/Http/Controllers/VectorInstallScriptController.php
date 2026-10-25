<?php

namespace Rconfig\VectorServer\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\Response;
use Rconfig\VectorServer\Services\VectorAgentInstaller;

class VectorInstallScriptController extends Controller
{
    /**
     * The retired `install.sh?bootstrap_token=` URL (RCO-1817). Old commands
     * pipe this into a root shell, so it returns a script that only says where
     * the new command is and exits 1. It is a 200 so the message is shown;
     * the token is ignored and never burned.
     */
    public function install(VectorAgentInstaller $installer): Response
    {
        return $this->script($installer->retiredScriptPath());
    }

    /**
     * The static agent installer, byte for byte. Nothing in it is rendered or
     * read from a web-user-writable view.
     */
    public function agentInstaller(VectorAgentInstaller $installer): Response
    {
        return $this->script($installer->scriptPath());
    }

    protected function script(string $path): Response
    {
        return response((string) file_get_contents($path), 200)
            ->header('Content-Type', 'text/x-sh');
    }
}
