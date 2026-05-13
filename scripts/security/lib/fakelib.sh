#!/usr/bin/env bash
# ============================================================================
# Paper-5 (Satellite ZTA) fakelib helper.
#
# Generates a benign-but-marker-leaving .so for LD_PRELOAD injection tests.
# The constructor touches /tmp/hijacked so any process that loads the lib
# leaves an audit trail. The lib itself contains NO malicious code — it is
# a forensic marker only.
#
# Public functions:
#   make_fake_so_on_host <ssh_alias> <out_path>
#       Compiles evil.so on the remote host via gcc. Echoes "MISSING_GCC"
#       on stdout if gcc is unavailable.
#
#   inject_into_container <ssh_alias> <container> <local_so_in_container>
#       docker cp + cleanup any prior marker.
#
#   cleanup_container <ssh_alias> <container>
#       Removes /tmp/evil and /tmp/hijacked inside the container.
#
#   hijacked_marker <ssh_alias> <container>
#       Echoes "yes" if /tmp/hijacked exists in container, "no" otherwise.
# ============================================================================

make_fake_so_on_host() {
    local host_alias=$1 out_path=$2
    ssh "$host_alias" "
        set -e
        TMPDIR=\$(mktemp -d)
        cat > \$TMPDIR/evil.c <<'CSRC'
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
        if ! command -v gcc >/dev/null 2>&1; then
            rm -rf \$TMPDIR
            echo MISSING_GCC
            exit 0
        fi
        gcc -shared -fPIC -o '$out_path' \$TMPDIR/evil.c 2>&1
        rm -rf \$TMPDIR
    " 2>&1
}

inject_into_container() {
    local host_alias=$1 container=$2 lib_path=$3
    ssh "$host_alias" "
        docker exec '$container' mkdir -p /tmp/evil 2>/dev/null || true
        docker exec '$container' sh -c 'rm -f /tmp/evil/evil.so /tmp/hijacked' 2>/dev/null || true
        docker cp '$lib_path' '$container':/tmp/evil/evil.so
    " 2>&1
}

cleanup_container() {
    local host_alias=$1 container=$2
    ssh "$host_alias" "
        docker exec '$container' sh -c 'rm -rf /tmp/evil /tmp/hijacked' 2>/dev/null || true
    " 2>&1 || true
}

hijacked_marker() {
    local host_alias=$1 container=$2
    ssh "$host_alias" "
        if docker exec '$container' test -f /tmp/hijacked 2>/dev/null; then echo yes; else echo no; fi
    " 2>/dev/null | tail -1
}
