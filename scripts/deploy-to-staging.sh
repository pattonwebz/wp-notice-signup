#!/usr/bin/env bash
# Mirror a local directory to the staging server.
#
# Usage:  scripts/deploy-to-staging.sh <local-dir> <remote-abs-path>
#
# Environment:
#   DEPLOY_TRANSPORT   sftp (default) | rsync
#   DEPLOY_SSH_HOST    hostname
#   DEPLOY_SSH_USER    deploy user
#   SSH key is expected at ~/.ssh/deploy_key, known_hosts at ~/.ssh/known_hosts
#
# WHY TWO TRANSPORTS
# ------------------
# rsync needs a real remote shell: it starts `rsync --server` on the far end.
# A deploy account locked down with `ForceCommand internal-sftp` — which is what
# a chrooted, SFTP-only account looks like, and what setup-sftp-deploy-users.sh
# creates — cannot run it. rsync fails there with a confusing protocol error,
# not a clear "permission denied".
#
# So the default is `sftp`, using lftp's mirror, which speaks only SFTP and
# therefore works against the locked-down account. `rsync` remains available for
# a host where the deploy user does have a shell, because it is faster and its
# delta transfer matters on large trees.
#
# Both modes mirror with delete, so the remote directory ends up an exact copy
# of the local one.

set -Eeuo pipefail

local_dir="${1:?usage: deploy-to-staging.sh <local-dir> <remote-abs-path>}"
remote_path="${2:?usage: deploy-to-staging.sh <local-dir> <remote-abs-path>}"

transport="${DEPLOY_TRANSPORT:-sftp}"
host="${DEPLOY_SSH_HOST:?DEPLOY_SSH_HOST is not set}"
user="${DEPLOY_SSH_USER:?DEPLOY_SSH_USER is not set}"
key="${DEPLOY_SSH_KEY:-$HOME/.ssh/deploy_key}"
known_hosts="${DEPLOY_KNOWN_HOSTS:-$HOME/.ssh/known_hosts}"

if [ ! -d "$local_dir" ]; then
	echo "::error::Local directory '$local_dir' does not exist. Refusing to mirror nothing onto the server — with --delete that would empty the remote directory." >&2
	exit 1
fi

# An empty or relative remote path is how a deploy wipes the wrong directory.
case "$remote_path" in
	/*) ;;
	*)
		echo "::error::Remote path '$remote_path' must be absolute." >&2
		exit 1
		;;
esac

echo "Deploying '$local_dir' -> ${user}@${host}:${remote_path} via ${transport}"

case "$transport" in
	rsync)
		rsync -avz --delete \
			-e "ssh -i '$key' -o UserKnownHostsFile='$known_hosts'" \
			"${local_dir}/" \
			"${user}@${host}:${remote_path}/"
		;;

	sftp)
		if ! command -v lftp >/dev/null 2>&1; then
			echo "::error::lftp is not installed. On a GitHub-hosted runner add: sudo apt-get update && sudo apt-get install -y lftp" >&2
			exit 1
		fi

		# -R mirrors local -> remote. --delete makes it an exact mirror.
		# --verbose keeps the log readable when a deploy misbehaves.
		lftp -c "
			set sftp:auto-confirm no;
			set sftp:connect-program 'ssh -a -x -i $key -o UserKnownHostsFile=$known_hosts';
			open -u ${user}, sftp://${host};
			mirror -R --delete --verbose --exclude-glob .git* '${local_dir}/' '${remote_path}/';
			bye
		"
		;;

	*)
		echo "::error::Unknown DEPLOY_TRANSPORT '$transport'. Use 'sftp' or 'rsync'." >&2
		exit 1
		;;
esac

echo "Deploy complete."
