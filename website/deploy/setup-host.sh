#!/usr/bin/env bash
# The host's drop-ins for via.zweiundeins.gmbh (see README.md), as root from a copy of this folder:
#   bash setup-host.sh
# The unit's listen.conf, the probes and the Alloy file, each only where its service runs.
set -euo pipefail
NAME=via
UNIT=via
HERE=$(cd "$(dirname "$0")" && pwd)
OBS=$HERE/observability
# Alloy's HTTP server, for its reload endpoint.
ALLOY_URL=${ALLOY_URL:-http://127.0.0.1:12345}

installed() { [[ "$(systemctl show -p LoadState --value "$1" 2>/dev/null)" == loaded ]]; }

# Like the host's installer, this never restarts the app: a changed drop-in waits for its next restart.
systemd_dropins() {
	local src dst changed=
	for src in "$HERE/systemd/$UNIT.service.d"/*.conf; do
		[[ -f "$src" ]] || continue
		dst=/etc/systemd/system/$UNIT.service.d/$(basename "$src")
		cmp -s "$src" "$dst" && continue
		install -D -m 0644 "$src" "$dst"
		echo ">> $UNIT's drop-in: $dst"
		changed=1
	done
	if [[ -n "$changed" ]]; then
		systemctl daemon-reload
		echo ">> restart $UNIT to apply it: systemctl restart $UNIT"
	fi
}

# Prometheus watches the folder (file_sd), so it needs no reload.
prometheus_dropins() {
	if ! installed prometheus; then
		echo ">> no Prometheus here: no probes"
		return
	fi
	if [[ -f "$OBS/$NAME.targets.yml" ]]; then
		install -D -m 0644 "$OBS/$NAME.targets.yml" "/etc/prometheus/targets/$NAME.yml"
		echo ">> the blackbox exporter probes $NAME: /etc/prometheus/targets/$NAME.yml"
	fi
}

# Alloy loads every .alloy file in /etc/alloy as one config, and a file it
# refuses would stop the logs of all apps at its next start: the old one goes back.
alloy_dropin() {
	local src=$OBS/$NAME.alloy dst=/etc/alloy/$NAME.alloy old out
	[[ -f "$src" ]] || return 0
	if ! installed alloy; then
		echo ">> no Alloy here: no logs to Loki"
		return
	fi
	old=$(mktemp)
	cp "$dst" "$old" 2>/dev/null || rm -f "$old"
	install -D -m 0644 "$src" "$dst"
	if ! systemctl is-active --quiet alloy; then
		echo ">> Alloy loads $dst when it starts"
	elif out=$(curl -sS --fail-with-body --max-time 30 "$ALLOY_URL/-/reload" 2>&1); then
		echo ">> Alloy ships $NAME's logs: $dst"
	else
		if [[ -f "$old" ]]; then install -m 0644 "$old" "$dst"; else rm -f "$dst"; fi
		rm -f "$old"
		curl -sS --max-time 30 "$ALLOY_URL/-/reload" >/dev/null 2>&1 || true
		echo "!! Alloy refused the new $NAME.alloy and keeps what it had: $out" >&2
		exit 1
	fi
	rm -f "$old"
}

systemd_dropins
prometheus_dropins
alloy_dropin
