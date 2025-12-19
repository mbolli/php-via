# php-via Examples

## Quick Start

Run all examples simultaneously on different ports:

```bash
cd examples
./start-all.sh start
```

Then visit **http://localhost:3000** to see all live examples.

### Commands

```bash
./start-all.sh start           # Start all examples
./start-all.sh start --tail    # Start all examples and follow logs
./start-all.sh start -f        # Same as --tail (shorthand)
./start-all.sh stop            # Stop all examples
./start-all.sh restart         # Restart all examples
./start-all.sh restart --tail  # Restart and follow logs
./start-all.sh status          # Show running examples
./start-all.sh logs            # Tail logs for all examples
```

**Tip:** Use `--tail` or `-f` with `start` or `restart` to automatically follow logs after launching.

## Port Assignments

Each example runs on its own port to avoid conflicts:

| Example | Port | URL |
|---------|------|-----|
| Counter | 3001 | http://localhost:3001 |
| Counter Basic | 3002 | http://localhost:3002 |
| Greeter | 3003 | http://localhost:3003 |
| Todo List | 3004 | http://localhost:3004 |
| Components | 3005 | http://localhost:3005 |
| Chat Room | 3006 | http://localhost:3006 |
| Game of Life | 3007 | http://localhost:3007 |
| Global Notifications | 3008 | http://localhost:3008 |
| Stock Ticker | 3009 | http://localhost:3009 |
| Profile Demo | 3010 | http://localhost:3010 |
| Path Parameters | 3011 | http://localhost:3011 |
| All Scopes Demo | 3012 | http://localhost:3012 |

The landing page runs on port 3000 using PHP's built-in server.

## Architecture

### Why Separate Ports?

Since only one Swoole server instance can bind to a port at a time, each example needs its own port. This approach has several benefits:

1. **True isolation** - Each example has its own worker process and state
2. **Global scope works correctly** - Examples with global state (Game of Life, Chat) work as designed
3. **Independent restarts** - Can restart one example without affecting others
4. **Real-world simulation** - Mimics how you'd deploy multiple Via apps in production

## Running Individual Examples

You can also run any example individually:

```bash
php examples/counter.php
# Visit http://localhost:3001
```

Just make sure to stop other examples first to avoid port conflicts.

## For Production Deployment

This multi-port approach mirrors production deployment strategies:

1. **Behind a reverse proxy** - Use Nginx/Caddy to route domains/paths to different ports
2. **Process management** - Use systemd/supervisor to manage each Via app
3. **Load balancing** - Run multiple instances of the same app on different ports
4. **Independent scaling** - Scale popular examples independently

## Logs

Each example logs to `/tmp/via-{port}.log`. View all logs:

```bash
./start-all.sh logs
```

Or view a specific example:
```bash
tail -f /tmp/via-3001.log
```
