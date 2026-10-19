#!/bin/sh
# Local Playground for the AI module: Flurfunk from this checkout, the OpenAI and Anthropic
# providers from wordpress.org, OpenAI requests sent to a local OpenAI-compatible proxy
# (FLURFUNK_DEV_AI_PROXY in blueprint-ai.json). Optional: ANTHROPIC_API_KEY=… ./start-ai.sh to
# try the real Anthropic connector instead. Port 9600 (PORT=… to change).
set -e
cd "$(dirname "$0")/../.."
PORT="${PORT:-9600}"
if lsof -iTCP:"$PORT" -sTCP:LISTEN >/dev/null 2>&1; then
	echo "Port $PORT is already in use – the AI Playground is probably running: http://localhost:$PORT/wp-admin/admin.php?page=flurfunk"
	echo "Start another one with PORT=9601 $0, or stop the running one first."
	exit 0
fi
export PATH="/opt/homebrew/opt/node@24/bin:$PATH"
EXTRA=""
if [ -n "$ANTHROPIC_API_KEY" ]; then
	EXTRA="--define ANTHROPIC_API_KEY $ANTHROPIC_API_KEY"
fi
exec npx -y @wp-playground/cli@3.1.57 server --port="$PORT" --site-url="http://localhost:$PORT" --php=8.3 --login \
	--mount="$PWD:/wordpress/wp-content/plugins/flurfunk" \
	--mount="$PWD/tools/playground/flurfunk-dev-ai.php:/wordpress/wp-content/mu-plugins/flurfunk-dev-ai.php" \
	${RUNNER:+--mount="$RUNNER:/wordpress/ff-runner"} \
	--blueprint="$PWD/tools/playground/blueprint-ai.json" $EXTRA
