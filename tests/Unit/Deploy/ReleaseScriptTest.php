<?php

use Illuminate\Support\Str;

function deployScript(): string
{
    return base_path('scripts/deploy/release.php');
}

function makeCandidate(string $directory, string $marker): void
{
    mkdir($directory.'/public', 0755, true);
    file_put_contents($directory.'/public/index.php', "<?php echo '{$marker}';\n");
    file_put_contents($directory.'/public/up', $marker);
}

function runRelease(string $root, string $command, array $options = []): array
{
    $arguments = [
        PHP_BINARY,
        deployScript(),
        $command,
        '--root='.$root,
    ];

    foreach ($options as $key => $value) {
        $arguments[] = '--'.$key.'='.$value;
    }

    $process = proc_open(
        $arguments,
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
    );

    expect($process)->toBeResource();

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $status = proc_close($process);

    return [
        'status' => $status,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}

function releaseId(string $root, string $name = 'current'): ?string
{
    $link = $root.'/'.$name;
    if (! is_link($link)) {
        return null;
    }

    $target = readlink($link);
    expect($target)->toStartWith('releases/');

    return substr((string) $target, strlen('releases/'));
}

it('installs A then B with an atomic current/previous switch and rolls back to A', function () {
    $root = sys_get_temp_dir().'/outboss-release-'.Str::uuid();
    $a = $root.'/incoming/a';
    $b = $root.'/incoming/b';
    makeCandidate($a, 'release-a');
    makeCandidate($b, 'release-b');

    $first = runRelease($root, 'install', ['id' => 'rel-a', 'source' => $a]);
    expect($first['status'])->toBe(0);
    expect($first['stderr'])->toBe('');
    expect(releaseId($root))->toBe('rel-a');
    expect(is_link($root.'/current'))->toBeTrue();
    expect(readlink($root.'/current'))->toBe('releases/rel-a');
    expect(file_get_contents($root.'/current/public/up'))->toBe('release-a');

    $second = runRelease($root, 'install', ['id' => 'rel-b', 'source' => $b]);
    expect($second['status'])->toBe(0);
    expect(releaseId($root))->toBe('rel-b');
    expect(releaseId($root, 'previous'))->toBe('rel-a');
    expect(is_link($root.'/current'))->toBeTrue();
    expect(is_link($root.'/previous'))->toBeTrue();
    expect(readlink($root.'/current'))->toBe('releases/rel-b');
    expect(readlink($root.'/previous'))->toBe('releases/rel-a');
    clearstatcache(true);
    expect(realpath($root.'/current'))->toBe(realpath($root.'/releases/rel-b'));
    expect(realpath($root.'/previous'))->toBe(realpath($root.'/releases/rel-a'));
    expect(file_get_contents($root.'/current/public/up'))->toBe('release-b');

    $payload = json_decode($second['stdout'], true);
    expect($payload)->toMatchArray([
        'ok' => true,
        'command' => 'install',
        'current' => 'rel-b',
        'previous' => 'rel-a',
    ]);

    $rollback = runRelease($root, 'rollback');
    expect($rollback['status'])->toBe(0);
    expect(releaseId($root))->toBe('rel-a');
    expect(releaseId($root, 'previous'))->toBe('rel-b');
    clearstatcache(true);
    expect(file_get_contents($root.'/current/public/up'))->toBe('release-a');
});

it('leaves current unchanged when the candidate is invalid', function () {
    $root = sys_get_temp_dir().'/outboss-release-'.Str::uuid();
    $a = $root.'/incoming/a';
    $bad = $root.'/incoming/bad';
    makeCandidate($a, 'release-a');
    mkdir($bad.'/public', 0755, true);
    file_put_contents($bad.'/public/readme.txt', 'not an app');

    expect(runRelease($root, 'install', ['id' => 'rel-a', 'source' => $a])['status'])->toBe(0);

    $failed = runRelease($root, 'install', ['id' => 'rel-bad', 'source' => $bad]);

    expect($failed['status'])->not->toBe(0)
        ->and($failed['stderr'])->toContain('invalid candidate: missing public/index.php')
        ->and(releaseId($root))->toBe('rel-a')
        ->and(is_dir($root.'/releases/rel-bad'))->toBeFalse()
        ->and(file_get_contents($root.'/current/public/up'))->toBe('release-a');
});

it('finishes candidate preparation before switching the live current symlink', function () {
    $root = sys_get_temp_dir().'/outboss-release-'.Str::uuid();
    $a = $root.'/incoming/a';
    $b = $root.'/incoming/b';
    $log = $root.'/hook-order.log';
    $prepare = $root.'/prepare.sh';
    $post = $root.'/post.sh';
    makeCandidate($a, 'release-a');
    makeCandidate($b, 'release-b');

    expect(runRelease($root, 'install', ['id' => 'rel-a', 'source' => $a])['status'])->toBe(0);

    file_put_contents($prepare, sprintf(<<<'SH'
#!/bin/sh
set -eu
printf 'prepare:%%s:%%s\n' "$(basename "$(readlink -f "$OUTBOSS_RELEASE_CURRENT")")" "$(basename "$PWD")" >> %s
test "$(cat "$OUTBOSS_RELEASE_CURRENT/public/up")" = "release-a"
SH, escapeshellarg($log)));
    file_put_contents($post, sprintf(<<<'SH'
#!/bin/sh
set -eu
printf 'post:%%s:%%s\n' "$(basename "$(readlink -f "$OUTBOSS_RELEASE_CURRENT")")" "$(basename "$PWD")" >> %s
test "$(cat "$OUTBOSS_RELEASE_CURRENT/public/up")" = "release-b"
SH, escapeshellarg($log)));
    chmod($prepare, 0755);
    chmod($post, 0755);

    $installed = runRelease($root, 'install', [
        'id' => 'rel-b',
        'source' => $b,
        'prepare-cmd' => $prepare,
        'post-cmd' => $post,
    ]);

    expect($installed['status'])->toBe(0)
        ->and(file($log, FILE_IGNORE_NEW_LINES))->toBe([
            'prepare:rel-a:rel-b',
            'post:rel-b:rel-b',
        ])
        ->and(releaseId($root))->toBe('rel-b');
});

it('leaves the live release untouched when staged preparation fails', function () {
    $root = sys_get_temp_dir().'/outboss-release-'.Str::uuid();
    $a = $root.'/incoming/a';
    $b = $root.'/incoming/b';
    makeCandidate($a, 'release-a');
    makeCandidate($b, 'release-b');

    expect(runRelease($root, 'install', ['id' => 'rel-a', 'source' => $a])['status'])->toBe(0);

    $failed = runRelease($root, 'install', [
        'id' => 'rel-b',
        'source' => $b,
        'prepare-cmd' => 'exit 23',
    ]);

    expect($failed['status'])->not->toBe(0)
        ->and($failed['stderr'])->toContain('prepare-cmd failed (23)')
        ->and(releaseId($root))->toBe('rel-a')
        ->and(is_dir($root.'/releases/rel-b'))->toBeFalse()
        ->and(file_get_contents($root.'/current/public/up'))->toBe('release-a');
});

it('rolls current back when a post-activation health check fails', function () {
    $root = sys_get_temp_dir().'/outboss-release-'.Str::uuid();
    $a = $root.'/incoming/a';
    $b = $root.'/incoming/b';
    makeCandidate($a, 'release-a');
    makeCandidate($b, 'release-b');

    expect(runRelease($root, 'install', ['id' => 'rel-a', 'source' => $a])['status'])->toBe(0);

    $failed = runRelease($root, 'install', [
        'id' => 'rel-b',
        'source' => $b,
        'health-cmd' => 'exit 1',
    ]);

    expect($failed['status'])->not->toBe(0)
        ->and($failed['stderr'])->toContain('health-cmd failed')
        ->and(releaseId($root))->toBe('rel-a')
        ->and(is_dir($root.'/releases/rel-b'))->toBeFalse()
        ->and(file_get_contents($root.'/current/public/up'))->toBe('release-a');
});
