#!/usr/bin/env php
<?php

/**
 * Immutable Outboss release installer.
 *
 * Candidates are copied into releases/<id> only. Live current/previous
 * are atomic symlink replacements. Preparing a candidate never writes
 * into the live document root.
 */

declare(strict_types=1);

const REQUIRED_FILES = [
    'public/index.php',
];

function main(array $argv): int
{
    array_shift($argv);
    $command = $argv[0] ?? '';
    $options = parseOptions(array_slice($argv, 1));

    $root = $options['root'] ?? '';
    if ($root === '' || ! is_absolute_path($root)) {
        fwrite(STDERR, "error: --root must be an absolute path\n");

        return 2;
    }

    $root = rtrim($root, '/');

    try {
        return match ($command) {
            'install' => withLock($root, fn () => installRelease($root, $options)),
            'rollback' => withLock($root, fn () => rollbackRelease($root)),
            default => usage(),
        };
    } catch (RuntimeException $exception) {
        fwrite(STDERR, 'error: '.$exception->getMessage()."\n");

        return 1;
    }
}

function usage(): int
{
    fwrite(STDERR, <<<'TXT'
Usage:
  php scripts/deploy/release.php install --root=DIR --id=ID --source=DIR [--prepare-cmd=CMD] [--post-cmd=CMD] [--health-cmd=CMD]
  php scripts/deploy/release.php rollback --root=DIR

TXT);

    return 2;
}

/**
 * @param  list<string>  $argv
 * @return array<string, string>
 */
function parseOptions(array $argv): array
{
    $options = [];

    foreach ($argv as $argument) {
        if (! str_starts_with($argument, '--') || ! str_contains($argument, '=')) {
            throw new RuntimeException("invalid argument: {$argument}");
        }

        [$key, $value] = explode('=', substr($argument, 2), 2);
        $options[$key] = $value;
    }

    return $options;
}

function is_absolute_path(string $path): bool
{
    return str_starts_with($path, '/');
}

/**
 * @param  callable(): int  $callback
 */
function withLock(string $root, callable $callback): int
{
    if (! is_dir($root) && ! mkdir($root, 0755, true) && ! is_dir($root)) {
        throw new RuntimeException("cannot create deploy root {$root}");
    }

    $lockPath = $root.'/.deploy.lock';
    $handle = fopen($lockPath, 'c');
    if ($handle === false) {
        throw new RuntimeException("cannot open lock {$lockPath}");
    }

    if (! flock($handle, LOCK_EX)) {
        fclose($handle);
        throw new RuntimeException("cannot lock {$lockPath}");
    }

    try {
        return $callback();
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/**
 * @param  array<string, string>  $options
 */
function installRelease(string $root, array $options): int
{
    $id = $options['id'] ?? '';
    $source = $options['source'] ?? '';

    if ($id === '' || ! preg_match('/^[A-Za-z0-9._-]+$/', $id)) {
        throw new RuntimeException('invalid --id');
    }

    if ($source === '' || ! is_dir($source)) {
        throw new RuntimeException('invalid --source directory');
    }

    validateCandidate($source);

    $releases = $root.'/releases';
    if (! is_dir($releases) && ! mkdir($releases, 0755, true) && ! is_dir($releases)) {
        throw new RuntimeException("cannot create {$releases}");
    }

    $destination = $releases.'/'.$id;
    if (file_exists($destination)) {
        throw new RuntimeException("release {$id} already exists");
    }

    $staging = $destination.'.staging.'.getmypid();
    copyTree($source, $staging);
    validateCandidate($staging);
    linkShared($root, $staging);

    if (! rename($staging, $destination)) {
        removeTree($staging);
        throw new RuntimeException("cannot promote staging tree to {$destination}");
    }

    $previousCurrent = currentReleaseId($root);

    try {
        // Schema/data readiness must finish against the staged candidate
        // before nginx can serve it through the current symlink.
        if (isset($options['prepare-cmd']) && $options['prepare-cmd'] !== '') {
            runHook($root, $options['prepare-cmd'], 'prepare-cmd', $destination);
        }

        if ($previousCurrent !== null && $previousCurrent !== $id) {
            atomicReleaseLink($root, 'previous', $previousCurrent);
        }

        atomicReleaseLink($root, 'current', $id);

        if (isset($options['post-cmd']) && $options['post-cmd'] !== '') {
            runHook($root, $options['post-cmd'], 'post-cmd');
        }

        if (isset($options['health-cmd']) && $options['health-cmd'] !== '') {
            runHook($root, $options['health-cmd'], 'health-cmd');
        }
    } catch (Throwable $exception) {
        restorePrevious($root, $previousCurrent);
        removeTree($destination);
        throw new RuntimeException($exception->getMessage(), 0, $exception);
    }

    echo json_encode([
        'ok' => true,
        'command' => 'install',
        'current' => $id,
        'previous' => currentReleaseId($root, 'previous'),
    ], JSON_UNESCAPED_SLASHES)."\n";

    return 0;
}

function rollbackRelease(string $root): int
{
    $current = currentReleaseId($root);
    $previous = currentReleaseId($root, 'previous');

    if ($current === null) {
        throw new RuntimeException('no current release to roll back from');
    }

    if ($previous === null) {
        throw new RuntimeException('no previous release to restore');
    }

    if ($previous === $current) {
        throw new RuntimeException('previous and current point at the same release');
    }

    if (! is_dir($root.'/releases/'.$previous)) {
        throw new RuntimeException("previous release {$previous} is missing");
    }

    atomicReleaseLink($root, 'previous', $current);
    atomicReleaseLink($root, 'current', $previous);

    echo json_encode([
        'ok' => true,
        'command' => 'rollback',
        'current' => $previous,
        'previous' => $current,
    ], JSON_UNESCAPED_SLASHES)."\n";

    return 0;
}

function validateCandidate(string $path): void
{
    foreach (REQUIRED_FILES as $relative) {
        if (! is_file($path.'/'.$relative)) {
            throw new RuntimeException("invalid candidate: missing {$relative}");
        }
    }
}

function copyTree(string $source, string $destination): void
{
    $source = rtrim($source, '/');
    $command = sprintf(
        'rsync -a --exclude .git --exclude node_modules --exclude .deploy.lock --exclude public/hot %s/ %s/',
        escapeshellarg($source),
        escapeshellarg($destination),
    );

    exec($command, $output, $status);
    if ($status !== 0) {
        removeTree($destination);
        throw new RuntimeException('failed to copy candidate into staging');
    }
}

function linkShared(string $root, string $release): void
{
    $sharedEnv = $root.'/shared/.env';
    if (is_file($sharedEnv)) {
        replaceWithSymlink($release.'/.env', '../../shared/.env');
    }

    $sharedStorage = $root.'/shared/storage';
    if (is_dir($sharedStorage)) {
        $storage = $release.'/storage';
        if (is_link($storage) || is_file($storage)) {
            unlink($storage);
        } elseif (is_dir($storage)) {
            removeTree($storage);
        }
        replaceWithSymlink($storage, '../../shared/storage');
    }
}

function replaceWithSymlink(string $path, string $target): void
{
    if (is_link($path) || is_file($path)) {
        unlink($path);
    }

    if (! symlink($target, $path)) {
        throw new RuntimeException("cannot symlink {$path} -> {$target}");
    }
}

function atomicReleaseLink(string $root, string $name, string $releaseId): void
{
    $link = $root.'/'.$name;
    $temporary = sprintf('%s/.%s.new.%d.%s', $root, $name, getmypid(), bin2hex(random_bytes(4)));
    $target = 'releases/'.$releaseId;

    if (! symlink($target, $temporary)) {
        throw new RuntimeException("cannot create temporary {$name} link");
    }

    try {
        atomicReplace($temporary, $link);
    } catch (RuntimeException $exception) {
        @unlink($temporary);
        throw $exception;
    }
}

function atomicReplace(string $source, string $destination): void
{
    // PHP rename() follows a symlink-to-directory on macOS and moves the
    // source into the live tree. Python os.replace matches the server's
    // existing atomic current/previous cutover and does not follow.
    $command = sprintf(
        'python3 -c %s %s %s',
        escapeshellarg('import os, sys; os.replace(sys.argv[1], sys.argv[2])'),
        escapeshellarg($source),
        escapeshellarg($destination),
    );

    $output = [];
    $status = 0;
    exec($command.' 2>&1', $output, $status);

    if ($status !== 0) {
        throw new RuntimeException('cannot atomically replace '.$destination.': '.implode("\n", $output));
    }
}

function currentReleaseId(string $root, string $name = 'current'): ?string
{
    $link = $root.'/'.$name;
    if (! is_link($link)) {
        return null;
    }

    $target = readlink($link);
    if ($target === false) {
        return null;
    }

    $prefix = 'releases/';
    if (! str_starts_with($target, $prefix)) {
        throw new RuntimeException("{$name} symlink has an unexpected target: {$target}");
    }

    return substr($target, strlen($prefix));
}

function restorePrevious(string $root, ?string $previousCurrent): void
{
    if ($previousCurrent === null) {
        $current = $root.'/current';
        if (is_link($current)) {
            unlink($current);
        }

        return;
    }

    atomicReleaseLink($root, 'current', $previousCurrent);
}

function runHook(
    string $root,
    string $command,
    string $label,
    ?string $workingDirectory = null,
): void {
    $current = $root.'/current';
    $release = $workingDirectory ?? $current;
    $descriptor = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];

    $process = proc_open($command, $descriptor, $pipes, $release, [
        'OUTBOSS_DEPLOY_ROOT' => $root,
        'OUTBOSS_RELEASE_CURRENT' => $current,
        'OUTBOSS_RELEASE_PATH' => $release,
    ]);

    if (! is_resource($process)) {
        throw new RuntimeException("failed to start {$label}");
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    if ($status !== 0) {
        throw new RuntimeException(trim("{$label} failed ({$status}): {$stderr} {$stdout}"));
    }
}

function removeTree(string $path): void
{
    if (! file_exists($path) && ! is_link($path)) {
        return;
    }

    if (is_link($path) || is_file($path)) {
        unlink($path);

        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $itemPath = $item->getPathname();
        if ($item->isLink() || $item->isFile()) {
            unlink($itemPath);
        } else {
            rmdir($itemPath);
        }
    }

    rmdir($path);
}

exit(main($argv));
