#!/bin/bash
#
# Stop the WhatsApp queue worker
#
# Usage (from project root):
#   bash cli/stop_whatsapp_worker.sh
#
# Or make executable first:
#   chmod +x cli/stop_whatsapp_worker.sh
#   ./cli/stop_whatsapp_worker.sh
#

SCRIPT_NAME="whatsapp_worker.php"
PIDS=$(pgrep -f "$SCRIPT_NAME")

if [ -z "$PIDS" ]; then
    echo "WhatsApp worker is not running."
    exit 0
fi

echo "Stopping WhatsApp worker (PIDs: $PIDS)..."
for PID in $PIDS; do
    kill "$PID" 2>/dev/null && echo "  Stopped PID $PID" || echo "  Failed to stop PID $PID"
done

# Wait a moment, then force kill if still running
sleep 2
REMAINING=$(pgrep -f "$SCRIPT_NAME")
if [ -n "$REMAINING" ]; then
    echo "Force killing remaining processes..."
    pkill -9 -f "$SCRIPT_NAME" && echo "Done." || echo "Some processes could not be stopped."
fi

echo "WhatsApp worker stopped."
