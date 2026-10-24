#!/usr/bin/env bash
set -euo pipefail

# eTIGO API — PHP security updates without anyone watching
# Run as root. Safe to run again: it rewrites the same three files.
#
#   ssh root@<SERVER_IP> 'bash -s' < deployment/php-auto-update.sh
#
# PHP comes from the ondrej PPA, which Ubuntu's unattended-upgrades skips, so
# CVE-2026-109618/109619 sat unpatched on staging for three days (Oct 2026).
# This lets unattended-upgrades install PHP releases from the PPA, and after
# every package run restarts the supervisor programs (queue workers, Reverb,
# telemetry gateway) still running the replaced PHP binary. php-fpm is
# restarted by its own package.

if [ "$(id -u)" -ne 0 ]; then
    echo "Run as root." >&2
    exit 1
fi

echo "==> Allowing the PHP PPA in unattended-upgrades..."
cat > /etc/apt/apt.conf.d/51etigo-php-ppa << 'APT'
// E-tiGo: install PHP releases from the ondrej PPA automatically
// (deployment/php-auto-update.sh).
Unattended-Upgrade::Origins-Pattern {
        "origin=LP-PPA-ondrej-php,archive=${distro_codename}";
};
APT

echo "==> Installing the stale-PHP restart script..."
cat > /usr/local/sbin/etigo-restart-stale-php << 'SCRIPT'
#!/usr/bin/env bash
# Restart supervisor programs whose PHP binary a package upgrade replaced
# (the kernel shows it as "/usr/bin/php8.5 (deleted)"). Installed by
# deployment/php-auto-update.sh; apt runs it after every dpkg run.
set -u
command -v supervisorctl > /dev/null || exit 0

supervisorctl status 2> /dev/null | awk '$2 == "RUNNING" { gsub(",", "", $4); print $1, $4 }' |
while read -r name pid; do
    exe="$(readlink "/proc/${pid}/exe" 2> /dev/null)" || continue
    case "${exe}" in
        */php*" (deleted)")
            logger -t etigo-restart-stale-php "restarting ${name}: pid ${pid} runs replaced ${exe% (deleted)}"
            supervisorctl restart "${name}" > /dev/null || logger -t etigo-restart-stale-php "restart of ${name} failed"
            ;;
    esac
done
exit 0
SCRIPT
chmod 755 /usr/local/sbin/etigo-restart-stale-php

echo "==> Hooking it into apt..."
cat > /etc/apt/apt.conf.d/52etigo-restart-stale-php << 'APT'
// E-tiGo: after packages change, restart supervisor programs left on a
// replaced PHP binary (deployment/php-auto-update.sh).
DPkg::Post-Invoke { "/usr/local/sbin/etigo-restart-stale-php || true"; };
APT

echo "==> Checking..."
unattended-upgrade --dry-run --debug 2>&1 | grep -i "allowed origins" || true
/usr/local/sbin/etigo-restart-stale-php
echo "Done. Restarts are logged to syslog under etigo-restart-stale-php."
