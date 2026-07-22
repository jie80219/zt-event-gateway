#!/usr/bin/env bash
# LD_PRELOAD evil.so generator for CVE#8 (Library Hijack) probes.
#
# Compiles a marker-only shared library on a remote host via gcc. The library's
# constructor touches /tmp/hijacked inside whichever process loads it, leaving
# a forensic breadcrumb without altering program behaviour. Used to verify
# whether container hardening (read_only + tmpfs noexec + cap_drop ALL) blocks
# library injection.
#
# Public API (all functions assume the caller has $GATEWAY_ALIAS etc. defined):
#   make_fake_so_on_host <alias> <remote_path>
#       Compiles evil.so on the remote host. Stdout: "ok" / "MISSING_GCC" /
#       "BUILD_FAILED:<message>".
#
#   inject_into_container <alias> <container> <remote_so_path>
#       docker cp the so into /tmp/evil/evil.so. Stdout: "ok" / "FAILED:<msg>".
#
#   hijacked_marker <alias> <container>
#       "yes" if /tmp/hijacked exists in the container, else "no".
#
#   cleanup_fakelib <alias> <container>
#       Best-effort removal of injected files.

make_fake_so_on_host() {
    local alias=$1 out_path=$2
    local result
    result=$(ssh -n "$alias" "
        set -e
        if ! command -v gcc >/dev/null 2>&1; then
            echo MISSING_GCC
            exit 0
        fi
        TMPD=\$(mktemp -d)
        cat >\$TMPD/evil.c <<'CSRC'
#include <stdio.h>
#include <stdlib.h>
#include <unistd.h>
#include <time.h>
__attribute__((constructor))
static void p5_marker(void) {
    FILE *f = fopen(\"/tmp/hijacked\", \"a\");
    if (f) {
        fprintf(f, \"hijacked pid=%d ts=%ld\\n\", getpid(), (long)time(NULL));
        fclose(f);
    }
}
CSRC
        if ! gcc -shared -fPIC -o '$out_path' \$TMPD/evil.c 2>\$TMPD/err; then
            echo \"BUILD_FAILED:\$(cat \$TMPD/err | tr '\\n' ' ' | head -c 200)\"
            rm -rf \$TMPD
            exit 0
        fi
        rm -rf \$TMPD
        echo ok
    " 2>&1 | tail -1)
    echo "$result"
}

inject_into_container() {
    local alias=$1 container=$2 src=$3
    local result
    result=$(ssh -n "$alias" "
        docker exec '$container' sh -c 'rm -rf /tmp/evil /tmp/hijacked' >/dev/null 2>&1 || true
        if ! docker exec '$container' mkdir -p /tmp/evil 2>err.txt; then
            echo \"FAILED:mkdir-\$(cat err.txt | tr '\\n' ' ' | head -c 80)\"
            exit 0
        fi
        if ! docker cp '$src' '$container':/tmp/evil/evil.so 2>err.txt; then
            echo \"FAILED:cp-\$(cat err.txt | tr '\\n' ' ' | head -c 80)\"
            exit 0
        fi
        echo ok
    " 2>&1 | tail -1)
    echo "$result"
}

hijacked_marker() {
    local alias=$1 container=$2
    ssh -n "$alias" "
        if docker exec '$container' test -f /tmp/hijacked 2>/dev/null; then
            echo yes
        else
            echo no
        fi
    " 2>/dev/null | tail -1
}

cleanup_fakelib() {
    local alias=$1 container=$2
    ssh -n "$alias" "docker exec '$container' sh -c 'rm -rf /tmp/evil /tmp/hijacked' >/dev/null 2>&1" || true
}
