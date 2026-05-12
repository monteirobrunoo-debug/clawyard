#!/usr/bin/env bash
# ============================================================================
# Forge zero-downtime keeps N releases under /home/forge/<site>/releases/
# We've seen >5 left behind. Each is ~500MB → ~2.5GB wasted on disk over time.
#
# Keep the last 5; nuke the rest. Idempotent. Cron: weekly Sunday 04:00.
#
# Install:
#   chmod +x /home/forge/clawyard.partyard.eu/current/scripts/cleanup-old-releases.sh
#   echo '0 4 * * 0 /home/forge/clawyard.partyard.eu/current/scripts/cleanup-old-releases.sh' | crontab -
# ============================================================================
set -euo pipefail

SITE="/home/forge/clawyard.partyard.eu"
KEEP=5

cd "$SITE/releases"

# Get currently-symlinked release; NEVER delete it.
CURRENT_TARGET="$(readlink "$SITE/current" 2>/dev/null | xargs -n1 basename || echo "")"

# List releases newest-first; tail to find the ones to drop.
to_delete=$(ls -1t | grep -v "^${CURRENT_TARGET:-__none__}\$" | tail -n +$((KEEP+1)) || true)

if [[ -z "$to_delete" ]]; then
    echo "[$(date)] nothing to clean (≤ $KEEP releases)"
    exit 0
fi

echo "[$(date)] keeping last $KEEP + current ($CURRENT_TARGET)"
for r in $to_delete; do
    echo "[$(date)] removing $r ($(du -sh "$r" 2>/dev/null | cut -f1))"
    rm -rf "$r"
done

echo "[$(date)] done. disk free: $(df -h / | awk 'NR==2{print $4}')"
