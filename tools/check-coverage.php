<?php

declare(strict_types=1);

$path = $argv[1] ?? dirname(__DIR__).'/build/coverage.xml';
libxml_use_internal_errors(true);
$xml = is_file($path) ? simplexml_load_file($path, SimpleXMLElement::class, LIBXML_NONET) : false;
if ($xml === false || ! isset($xml->project->metrics)) {
    fwrite(STDERR, "Cannot read Clover coverage metrics from {$path}.\n");
    exit(1);
}

$metric = static function (SimpleXMLElement $metrics, string $name): int {
    $value = (string) $metrics[$name];
    if ($value === '' || ! ctype_digit($value)) {
        fwrite(STDERR, "Invalid or missing coverage metric: {$name}.\n");
        exit(1);
    }

    return (int) $value;
};

$metrics = $xml->project->metrics;
$classes = $xml->xpath('/coverage/project//file/class') ?: [];
$coveredClasses = 0;
$measurableClasses = 0;
foreach ($classes as $class) {
    $methods = $metric($class->metrics, 'methods');
    $coveredMethods = $metric($class->metrics, 'coveredmethods');
    $statements = $metric($class->metrics, 'statements');
    $coveredStatements = $metric($class->metrics, 'coveredstatements');
    if ($methods === 0 && $statements === 0) {
        continue;
    }
    $measurableClasses++;
    if ($methods === $coveredMethods && $statements === $coveredStatements) {
        $coveredClasses++;
    }
}

$counts = [
    'Classes' => [$metric($metrics, 'classes'), $coveredClasses],
    'Methods' => [$metric($metrics, 'methods'), $metric($metrics, 'coveredmethods')],
    'Lines'   => [$metric($metrics, 'statements'), $metric($metrics, 'coveredstatements')],
];
$passed = $measurableClasses === $counts['Classes'][0];
$summary = "## Code coverage\n\nRequired coverage: **100%** for classes, methods and lines.\n\n";
foreach ($counts as $label => [$total, $covered]) {
    $percent = $total > 0 ? 100 * $covered / $total : 0;
    $line = sprintf("%s: %.2f%% (%d/%d)\n", $label, $percent, $covered, $total);
    fwrite(STDOUT, $line);
    $summary .= '- '.$line;
    $passed = $passed && $total > 0 && $total === $covered;
}

$summaryPath = getenv('GITHUB_STEP_SUMMARY');
if (is_string($summaryPath) && $summaryPath !== '') {
    file_put_contents($summaryPath, $summary, FILE_APPEND);
}

if (! $passed) {
    fwrite(STDERR, "Coverage must be exactly 100% for every metric.\n");
    exit(1);
}
