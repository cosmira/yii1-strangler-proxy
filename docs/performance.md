# Measure file performance

The proxy passes selected uploads as read-only file streams. With Guzzle's default
cURL handler, large multipart bodies are streamed rather than materialized as one
PHP string. cURL may buffer small bodies. Install `ext-curl` for that path.
Without cURL, Guzzle's PHP stream handler converts the
request body to a string, so upload memory can grow with the file size.

Responses remain buffered before the proxy sends headers and output. Size PHP's
memory limit for the largest allowed response and its mapping. Unmapped responses,
including binary files and redirects, reuse the captured body. Selected JSON
mappings retain a separate writable response, including for read-only or
nonseekable upstream streams.

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
PCOV is disabled and CLI OPcache is enabled. Five measured requests per operation
and size report milliseconds, worker CPU time, absolute peak PHP allocation, and
additional allocation above the warm baseline. Zero additional allocation means
that the existing PHP heap was sufficient, not that the request used no memory.
PHP allocation excludes libcurl memory, process RSS, and the backend process.

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

## Recorded comparison

The following run compares 2.0.5 with 2.0.6 on macOS, PHP 8.5.11, libcurl 8.22.0,
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
