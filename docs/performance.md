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
PCOV is disabled and CLI OPcache is enabled. Each measured request reports
milliseconds, worker CPU time, absolute peak PHP allocation, and additional
allocation above the warm baseline. Zero additional allocation means that the
existing PHP heap was sufficient, not that the request used no memory.
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

Timing is not a CI threshold. CI checks complete, correct transfers and stores
measurements as an artifact. Inspect the raw runs and machine load before drawing
conclusions about elapsed time.

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
body in its heap. The report also records process peak RSS, which includes native
allocations and the warm-up. It does not measure concurrent transfers or reverse
proxy buffering. A memory-limit failure stops the run; the benchmark does not retry
with an unlimited memory limit.

### Recorded 4.0.0 run

[The 4.0.0 CI run on PHP 8.4.27](https://github.com/cosmira/yii1-strangler-proxy/actions/runs/42531544902)
completed both directions with a `128M` worker limit. The
[raw report](https://github.com/cosmira/yii1-strangler-proxy/releases/download/4.0.0/files-benchmark.json)
is attached to the release.

| Direction | Verified bytes | Peak PHP heap | Peak process RSS |
| --- | ---: | ---: | ---: |
| Download | 1,073,741,824 | 6 MiB | 75.7 MiB |
| Upload | 1,073,741,824 | 4 MiB | 73.2 MiB |

Each direction had one warm-up and one measured request. Byte counts and SHA-256
matched the fixture in both directions. These results demonstrate bounded memory
for this case. A single loopback run does not establish production throughput.
The default HTTP handler can spool the response to temporary disk.
