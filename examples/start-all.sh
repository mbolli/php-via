#!/usr/bin/env bash

# php-via Examples Launcher
# Starts all examples on different ports with a unified landing page

set -e

# Disable Xdebug for better performance
export XDEBUG_MODE=off

# Colors for output
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
MAGENTA='\033[0;35m'
CYAN='\033[0;36m'
NC='\033[0m' # No Color

# Base directory
BASEDIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PIDFILE="$BASEDIR/.examples-pids"

# Port assignments for each example
declare -A EXAMPLES=(
    ["counter.php"]="3001:⚡ Counter"
    ["counter_basic.php"]="3002:🔢 Counter Basic"
    ["greeter.php"]="3003:👋 Greeter"
    ["todo.php"]="3004:✓ Todo List"
    ["components.php"]="3005:🧩 Components"
    ["chat_room.php"]="3006:💬 Chat Room"
    ["game_of_life.php"]="3007:🎮 Game of Life"
    ["global_notifications.php"]="3008:🔔 Global Notifications"
    ["stock_ticker.php"]="3009:📈 Stock Ticker"
    ["profile_demo.php"]="3010:👤 Profile Demo"
    ["path_params.php"]="3011:🛣️  Path Parameters"
    ["all_scopes.php"]="3012:📊 All Scopes Demo"
)

# Landing page port
LANDING_PORT=3000

function show_banner() {
    echo -e "${MAGENTA}"
    echo "╔════════════════════════════════════════╗"
    echo "║                                        ║"
    echo "║     ⚡ php-via Examples Launcher       ║"
    echo "║                                        ║"
    echo "╚════════════════════════════════════════╝"
    echo -e "${NC}"
}

function check_requirements() {
    if ! command -v php &> /dev/null; then
        echo -e "${RED}Error: PHP is not installed${NC}"
        exit 1
    fi
    
    if ! php -m | grep -q swoole; then
        echo -e "${RED}Error: Swoole extension is not installed${NC}"
        exit 1
    fi
}

function stop_examples() {
    echo -e "${YELLOW}Stopping all examples...${NC}"
    
    if [ -f "$PIDFILE" ]; then
        while IFS= read -r pid; do
            if ps -p "$pid" > /dev/null 2>&1; then
                kill "$pid" 2>/dev/null || true
                echo -e "${GREEN}  Stopped process $pid${NC}"
            fi
        done < "$PIDFILE"
        rm -f "$PIDFILE"
    fi
    
    # Also kill any php processes running examples
    pkill -f "php.*examples/" 2>/dev/null || true
    
    echo -e "${GREEN}All examples stopped${NC}"
}

function start_landing_page() {
    echo -e "${CYAN}Starting landing page on port $LANDING_PORT...${NC}"
    
    # Clear PID file before starting anything
    > "$PIDFILE"
    
    # Start the landing page as a Swoole server (like other examples)
    cd "$BASEDIR"
    nohup php index.php > "/tmp/via-$LANDING_PORT.log" 2>&1 &
    local landing_pid=$!
    disown $landing_pid 2>/dev/null || true  # Detach from shell
    echo $landing_pid >> "$PIDFILE"
    
    echo -e "${GREEN}  Landing page: http://localhost:$LANDING_PORT/${NC}"
}

function start_examples() {
    echo -e "${CYAN}Starting all examples...${NC}\n"
    
    for example in "${!EXAMPLES[@]}"; do
        IFS=':' read -r port title <<< "${EXAMPLES[$example]}"
        
        if [ ! -f "$BASEDIR/$example" ]; then
            echo -e "${YELLOW}  Skipping $example (file not found)${NC}"
            continue
        fi
        
        echo -e "${BLUE}  Starting $title on port $port...${NC}"
        
        # Start the example in the background
        cd "$BASEDIR"
        nohup php "$example" > "/tmp/via-$port.log" 2>&1 &
        local pid=$!
        disown $pid 2>/dev/null || true  # Detach from shell
        echo $pid >> "$PIDFILE"
        
        echo -e "${GREEN}    ✓ Running (PID: $pid, Port: $port, URL: http://localhost:$port)${NC}"
    done
    
    echo ""
    echo -e "${GREEN}All examples started!${NC}\n"
}

function show_status() {
    echo -e "${CYAN}Running examples:${NC}\n"
    
    for example in "${!EXAMPLES[@]}"; do
        IFS=':' read -r port title <<< "${EXAMPLES[$example]}"
        echo -e "  $title"
        echo -e "    ${BLUE}http://localhost:$port${NC}"
    done
    
    echo ""
    echo -e "${MAGENTA}Landing Page:${NC}"
    echo -e "  ${BLUE}http://localhost:$LANDING_PORT/${NC}"
    echo ""
}

function show_logs() {
    echo -e "${CYAN}Tailing logs (Ctrl+C to stop)...${NC}\n"
    tail -f /tmp/via-*.log
}

function main() {
    local follow_logs=false
    
    # Check for --tail or -f flag
    if [[ "$2" == "--tail" ]] || [[ "$2" == "-f" ]]; then
        follow_logs=true
    fi
    
    case "${1:-start}" in
        start)
            show_banner
            check_requirements
            stop_examples
            sleep 1
            start_landing_page
            start_examples
            
            if [ "$follow_logs" = true ]; then
                echo ""
                show_logs
            fi
            ;;
        stop)
            show_banner
            stop_examples
            ;;
        restart)
            show_banner
            stop_examples
            sleep 1
            start_landing_page
            start_examples
            
            if [ "$follow_logs" = true ]; then
                echo ""
                show_logs
            fi
            ;;
        status)
            show_banner
            show_status
            ;;
        logs)
            show_banner
            show_logs
            ;;
        *)
            echo "Usage: $0 {start|stop|restart|status|logs} [--tail|-f]"
            echo ""
            echo "Commands:"
            echo "  start     Start all examples"
            echo "  stop      Stop all examples"
            echo "  restart   Restart all examples"
            echo "  status    Show running examples"
            echo "  logs      Tail all logs"
            echo ""
            echo "Options:"
            echo "  --tail, -f    Follow logs after starting (use with start/restart)"
            exit 1
            ;;
    esac
}

main "$@"
