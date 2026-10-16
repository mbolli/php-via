# Deploying the website

via.zweiundeins.gmbh is `website/app.php` on the shared host: the systemd unit `via`, on `127.0.0.1:7100`, behind Caddy. The host keeps its own configs for Caddy, Prometheus and Alloy, and each loads a folder of files that the apps install: lith's guide [Metrics and monitoring](https://lith.zweiundeins.gmbh/docs/guides/monitoring#drop-in-files-for-a-shared-host) has the folders and the labels. The site's are in this folder:

- `via.caddy` as `/etc/caddy/sites/via.caddy`: the site block. It proxies to `127.0.0.1:7100` over h2c, answers `/metrics` with a 404, sets the security headers and writes its JSON access log to `/var/log/caddy/via-access.log`.
- `systemd/via.service.d/listen.conf` as `/etc/systemd/system/via.service.d/listen.conf`: `VIA_HOST=127.0.0.1`, so the port and `/metrics` stay on loopback.
- `observability/via.targets.yml` as `/etc/prometheus/targets/via.yml`: a blackbox probe of `/` (`http_2xx_via`, which wants "php-via" in the body). Prometheus watches the folder.
- `observability/via.alloy` as `/etc/alloy/via.alloy`: Caddy's access log as `{job="caddy", site="via"}`, through the host's pipeline for access logs.

The host's Prometheus scrapes `/metrics` with its own `via` job, which stays in the host's config. `setup-host.sh` installs the unit's drop-in with a `daemon-reload` (it never restarts `via`: restart it to apply a changed one), then the probe and the Alloy file, each only where its service is installed. It reloads Alloy and puts the old file back if Alloy refuses the new one. Run it again after changing them:

```sh
scp -r website/deploy root@host:/tmp/via-deploy
ssh root@host bash /tmp/via-deploy/setup-host.sh
```

The Caddy block goes in by hand, since Caddy asks for the certificate as it loads it. As root, from the copied folder:

```sh
install -m 0644 via.caddy /etc/caddy/sites/
runuser -u caddy -- env HOME=/var/lib/caddy caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
systemctl reload caddy
```

Validate as Caddy's user: `caddy validate`, run as root, creates the log file owned by root, and the reload then fails.
