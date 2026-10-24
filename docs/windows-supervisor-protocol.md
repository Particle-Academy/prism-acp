# Windows supervisor

The PHP package owns `resources/windows/supervisor.cs`. It does not read a sibling package at runtime. The .NET
Framework compiler builds a source-hash-addressed executable; the recorded executable hash is verified on launch.
A missing compiler or failed build refuses containment.

The host binds an ephemeral IPv4 loopback listener, generates a 256-bit token and starts the supervisor with
`PRISM_ACP_TREE_CHANNEL` and `PRISM_ACP_TREE_TOKEN`. Four authenticated sockets carry control, stdin, stdout and
stderr. Each handshake has a deadline and bounded line length. Tokens are removed before provider creation.

Control messages are ASCII lines: `hello TOKEN`, `auth TOKEN`, `spawn BASE64_APPLICATION BASE64_COMMAND_LINE
BASE64_CWD`, `started PID` or `failed CODE`, then `exited CODE` and `empty COUNT`. `kill` or control EOF ends the job.
Each stream uses `stdio TOKEN FD` / `auth TOKEN FD` before raw bytes. Independent bounded-memory pumps prevent
output from blocking the control listener. Output is drained before the final report.

The provider is created suspended, assigned to a kill-on-close Job Object with no breakaway, then resumed.
Assignment failure terminates the suspended process. Only the supervisor holds the job handle.

`PRISM_ACP_TREE_INHERIT_STDIN=1` is an internal mode for the PHP stdin relay: the supervisor receives the host's
real stdin descriptor and gives it directly to the suspended relay. The other streams retain authenticated TCP
transport. The flag is removed before the relay runs. Normal providers always use a fresh stdin pipe. A closed
host control socket ends the relay's job even while its real pipe read is blocked.

Loopback authentication separates sessions; it does not defend against a process that can read this user's memory
or alter the installed package. A missing final report yields an unverified stop verdict, not observed emptiness.
