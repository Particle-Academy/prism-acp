#!/bin/bash
# CI only (Linux): run "$@" inside a cgroup v2 delegated to this user, so cgroup containment is exercised for real.
# The package itself never uses sudo or systemd; this stands in for a host that runs under a systemd user service or
# scope with Delegate=yes. Tried in order: this job's own cgroup, a systemd user scope, then a cgroup made with sudo.
set -u

probe() {
  rel=$(sed -n 's/^0:://p' /proc/self/cgroup)
  echo "cgroup=${rel} uid=$(id -u)"
  ls -ld "/sys/fs/cgroup${rel}" "/sys/fs/cgroup${rel}/cgroup.procs" 2>&1
  echo "subtree_control=[$(cat "/sys/fs/cgroup${rel}/cgroup.subtree_control" 2>&1)]"
}

echo "== this job's own cgroup"
probe
delegated() {
  php -r 'require "vendor/autoload.php"; $d = Prism\Acp\Process\PosixCgroup::delegated(); echo $d["reason"], "\n"; exit($d["path"] === null ? 1 : 0);'
}
if delegated; then
  echo "== already delegated"
  exec "$@"
fi
if command -v systemd-run >/dev/null 2>&1 && timeout 20 systemd-run --user --scope -p Delegate=yes --quiet true >/dev/null 2>&1; then
  echo "== using: systemd-run --user --scope -p Delegate=yes"
  exec systemd-run --user --scope -p Delegate=yes --quiet "$@"
fi
echo "== no systemd user manager here; delegating a cgroup with sudo (CI setup only)"
cg="/sys/fs/cgroup/prism-acp-ci-$$"
if ! sudo -n mkdir "$cg" || ! sudo -n chown -R "$(id -u):$(id -g)" "$cg" || ! echo $$ | sudo -n tee "$cg/cgroup.procs" >/dev/null; then
  echo "::error::could not obtain a delegated cgroup v2 on this runner"
  exit 1
fi
probe
exec "$@"
