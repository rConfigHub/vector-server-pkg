#!/usr/bin/env bash
#
# Served at the retired /vector/install.sh URL (RCO-1817). Piping a script
# from the rConfig server straight into a root shell is no longer supported;
# this only tells the admin where the new command is, then stops.

{
    echo "This rConfig Vector agent install command has been retired, and nothing was installed."
    echo ""
    echo "In rConfig, open Settings > Agents and copy the new install command for this agent."
    echo "The new command checks the installer against the SHA256 published in the rConfig docs:"
    echo "https://docs.rconfig.com/installation-upgrades/vector/agent-installation/"
} >&2

exit 1
