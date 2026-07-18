# Pty

Creates pseudo-terminal pairs and starts child processes attached to them. This
is the capability with no route through core PHP at all: `posix_openpt` does not
exist, and `proc_open` yields pipes rather than a Tty. FFI is not a preference
here but the only option, so Pty requires it unconditionally — see
[ADR-0001](../docs/adr/0001-ffi-for-terminal-primitives.md).

## Language

**Controller**:
The end of a Pty held by the parent, which writes input to and reads output from the child. Called `master` in libc, and only there — see below.
_Avoid_: master, primary, host

**Device**:
The end of a Pty that a child process sees as its Tty. Called `slave` in libc, and only there — see below.
_Avoid_: slave, secondary, peripheral

**Spawn**:
Starting a child process with its standard streams attached to a Device.
_Avoid_: fork, exec, launch, run

### On libc's vocabulary

`openpty` declares `int *amaster, int *aslave`, and that is an external
specification we do not get to rename. Those names stay inside the FFI boundary,
where they must match the C declarations a reader will check against the man
page. They do not appear in Pty's own API, which says Controller and Device.
