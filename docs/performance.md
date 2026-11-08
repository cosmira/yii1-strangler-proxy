# Measure file performance

The proxy passes selected uploads as read-only file streams. With Guzzle's default
cURL handler, large multipart bodies are streamed rather than materialized as one
PHP string. cURL may buffer small bodies. Install `ext-curl` for that path.
Without cURL, Guzzle's PHP stream handler converts the
request body to a string, so upload memory can grow with the file size.

Responses without a selected JSON mapper are forwarded in chunks of at most 64 KiB.
The package does not convert those bodies into a file-sized PHP string. Guzzle's
default cURL handler may first spool an upstream response to `php://temp`, which
can use disk. This bounds PHP memory; it does not guarantee immediate delivery of
the first byte or eliminate temporary-file I/O.

Selected JSON mappings still buffer, decode, and normalize their bodies before
output, including read-only and nonseekable upstream streams. Do not select a JSON
mapper for an archive download. A stream failure after output starts cannot be
replaced by a JSON error response. Host error handlers must log that failure without
appending an API error envelope to a partial file. The proxy never retries it.

A file route must not select a JSON response mapper. For example, a pass-through
archive contract can use:

```php
use Cosmira\Strangler\Strangler;

Strangler::proxy('archives')
    ->mapFiles(['file' => 'archive'], ['upload'])
    ->post('upload', '/archives')
    ->get('download', '/archives/{id}')
    ->build();
```

With `uppercase()`, exclude binary actions using `except`, and scope any explicit
`mapJsonResponse()` to JSON actions. Body-logging middleware and host output
buffering can still collect a whole file; measure the application stack separately.

## Run the benchmark

From a package checkout with development dependencies installed, on Linux or macOS:

```shell
composer benchmark:files
```

The command requires `ext-curl`, `proc_open`, and loopback sockets. It creates files
of 1 and 16 MiB, starts a local PHP backend, and exercises the real proxy and Guzzle
client. The backend returns downloaded bytes or the size and SHA-256 of an uploaded
file. Every transfer must return HTTP 200 and match the expected size and hash.
Failures stop the command with a nonzero exit code.

Each result comes from a separate PHP process with one untimed warm-up request.
`--rounds=N` controls the number of measured requests; the default is five.
PCOV is disabled and CLI OPcache is enabled. Five measured requests per operation
and size report milliseconds, worker CPU time, absolute peak PHP allocation, and
additional allocation above the warm baseline. Zero additional allocation means
that the existing PHP heap was sufficient, not that the request used no memory.
PHP allocation excludes libcurl memory and the backend process. `peak_rss_bytes`
reports the worker process's lifetime peak RSS, including warm-up and native
allocations; unlike PHP allocation, that OS counter cannot be reset for each run.

The timer covers request preparation, HTTP, response mapping, and output consumption.
Downloads are consumed by an incremental SHA-256 output handler. Upload timing
includes the backend's checksum. File creation, application setup, warm-up requests,
and the final comparison with the expected checksum are outside the timer.
The PHP test backend does not implement the HTTP 100-continue handshake, so the
benchmark client disables `Expect` on both sides of a comparison. This does not
change the package's production client defaults.

To compare the current source with the 2.0.5 tag:

```shell
baseline=$(mktemp -d)
git archive 2.0.5 | tar -x -C "$baseline"
composer benchmark:files -- "$baseline" > files-benchmark.json
rm -rf "$baseline"
```

The comparison alternates baseline and current workers for each case. Both sources
use the current checkout's installed dependencies to isolate the package change.
Workers check that the expected source checkout was loaded. Inspect the raw runs and the machine's
load before interpreting a median. Timing is not a CI threshold: CI checks complete,
correct transfers and stores the measurements as an artifact.

## Transfer 1 GiB in both directions

```shell
composer benchmark:files -- --size-mib=1024 --rounds=1 > files-1gib.json
```

The size is in MiB: 1024 MiB is exactly 1 GiB, or 1,073,741,824 bytes. The command
performs one warm-up and one measured request in each direction, transferring at
least 4 GiB over loopback. Every request checks the full byte count and SHA-256.
Larger sizes use the same option, for example `--size-mib=2048`.

Workers have a fixed `memory_limit=128M`. The benchmark raises the fixture's upload
limits above the selected size and uses a 300-second request timeout. Composer has
no overall process timeout for this command. Production PHP and web-server upload
limits and timeouts remain application configuration.

Allow several GiB of free temporary disk space for the fixture, uploaded files, and
HTTP-handler spooling. Run the large case separately from the ordinary CI suite.
The **Quality Assurance** workflow accepts `file-size-mib=1024` and `file-rounds=1`
when started manually; push and pull-request runs retain the small suite.

The report contains the actual byte counts, hashes, elapsed time, and PHP allocation.
Passing this case with a 128 MiB worker proves that PHP did not hold the whole 1 GiB
body in its heap. It does not measure process RSS, concurrent transfers, or reverse
proxy buffering. A 2.0.6 baseline can exceed that memory budget on download; such a
failure is reported, not silently retried with an unlimited memory limit.

## Historical comparison before chunked output

The following recorded run compares 2.0.5 with 2.0.6 on macOS, PHP 8.5.11, libcurl 8.22.0,
and 8 logical CPUs. Files and code were warm, concurrency was one, and the machine
also ran ordinary desktop workloads. Each entry summarizes five alternating runs.
[Raw A/B results](https://github.com/cosmira/yii1-strangler-proxy/releases/download/2.0.6/files-benchmark.json)
and an [A/A control](https://github.com/cosmira/yii1-strangler-proxy/releases/download/2.0.6/files-benchmark-control.json)
are attached to the release.

| Operation | File | 2.0.5 median ms (range) | 2.0.6 median ms (range) | Peak PHP MiB, before → after |
| --- | --- | --- | --- | --- |
| Download | 1 MiB | 16.67 (16.63–16.77) | 16.48 (16.36–16.70) | 14 → 10 |
| Download | 16 MiB | 263.53 (239.66–277.02) | 259.10 (208.15–278.09) | 68.05 → 52.03 |
| Upload | 1 MiB | 23.11 (21.87–29.66) | 22.29 (22.06–24.59) | 4 → 4 |
| Upload | 16 MiB | 293.24 (272.95–312.15) | 351.47 (281.76–387.48) | 4 → 4 |

The confirmed improvement is lower peak PHP allocation for unchanged downloads:
the 16 MiB response avoids approximately 16 MiB of additional allocation. Multipart
upload allocation stays flat in these cases. The proxy no longer constructs and
reads an extra PSR response when no response mapper is selected.

Transfer latency is inconclusive. The ranges overlap, and some upload samples are
slower. An A/A run of unchanged 2.0.5 produced upload medians of 312.49 and 286.60 ms
for the same 16 MiB file. The upload worker used approximately 18 ms of CPU, while
wall time included backend processing and I/O. Do not interpret this fixture as a
production speedup, throughput limit, or evidence about concurrent large uploads.
