<?php

// The Sellie build's NixOS behaviour has to survive every rebase onto a new upstream tag,
// and Coolify's own suite needs Composer and a database. This drives the real action
// classes through stubbed seams instead, so one `docker run php:8.4-cli-alpine` proves
// which branch a NixOS server takes.
//
//   docker run --rm -v "$PWD:/app" -w /app php:8.4-cli-alpine php scripts/sellie-nixos-check.php

declare(strict_types=1);

namespace Lorisleiva\Actions\Concerns {
    trait AsAction
    {
        public static function run(...$arguments)
        {
            return (new static)->handle(...$arguments);
        }
    }
}

namespace App\Models {
    class Server
    {
        public function __construct(public string $osId) {}

        public function serverStatus(): bool
        {
            return true;
        }

        public function validateOS(): \OsType
        {
            $matches = array_values(array_filter(SUPPORTED_OS, fn ($os) => str_contains($os, $this->osId)));

            return new \OsType(count($matches) === 1 ? $matches[0] : '');
        }
    }
}

namespace {

    use App\Actions\Server\CheckUpdates;
    use App\Actions\Server\InstallPrerequisites;
    use App\Models\Server;

    // Laravel's Stringable and Collection, narrowed to the methods the actions call.
    class OsType
    {
        public function __construct(private string $value) {}

        public function contains(string $needle): bool
        {
            return $this->value !== '' && str_contains($this->value, $needle);
        }
    }

    class CommandList
    {
        public array $items = [];

        public function merge(array $items): self
        {
            $this->items = array_merge($this->items, $items);

            return $this;
        }

        public function push(string $item): self
        {
            $this->items[] = $item;

            return $this;
        }
    }

    function collect(array $items = []): CommandList
    {
        return (new CommandList)->merge($items);
    }

    function remote_process($command, $server): string
    {
        return implode("\n", is_array($command) ? $command : $command->items);
    }

    function instant_remote_process(array $command, $server): string
    {
        if ($command[0] === 'cat /etc/os-release') {
            // NixOS derives ID from system.nixos.distroId, which defaults to nixos.
            return "NAME=NixOS\nID={$server->osId}\nVERSION_ID=\"25.05\"\nPRETTY_NAME=\"NixOS 25.05\"";
        }

        return '';
    }

    require __DIR__.'/../bootstrap/helpers/constants.php';
    require __DIR__.'/../app/Actions/Server/InstallPrerequisites.php';
    require __DIR__.'/../app/Actions/Server/CheckUpdates.php';

    $failures = [];
    $check = function (string $name, callable $assert) use (&$failures) {
        try {
            $assert();
            printf("  PASS  %s\n", $name);
        } catch (Throwable $e) {
            $failures[] = $name;
            printf("  FAIL  %s\n        %s\n", $name, $e->getMessage());
        }
    };
    $expect = function (bool $condition, string $message) {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    };

    echo "Sellie NixOS behaviour\n";

    $check('nixos resolves to exactly one supported OS entry', function () use ($expect) {
        $matches = array_filter(SUPPORTED_OS, fn ($os) => str_contains($os, 'nixos'));
        $expect(count($matches) === 1, 'expected one SUPPORTED_OS entry matching nixos, got '.count($matches));
    });

    $check('prerequisites on nixos are guidance, and never mutate the host', function () use ($expect) {
        $output = InstallPrerequisites::run(new Server('nixos'));
        $expect(str_contains($output, 'environment.systemPackages'), 'guidance must name the declarative option');
        foreach (['nix-env', 'nix-channel', 'nixos-rebuild switch', 'apt', 'dnf', 'zypper', 'pacman'] as $forbidden) {
            $expect(! str_contains($output, $forbidden), "guidance must not run {$forbidden}");
        }
        $expect(! str_contains($output, 'installed successfully'), 'nothing was installed, so nothing may claim it was');
    });

    $check('an apt host still installs prerequisites', function () use ($expect) {
        $output = InstallPrerequisites::run(new Server('ubuntu'));
        $expect(str_contains($output, 'apt-get update -y'), 'the debian branch must be untouched');
    });

    $check('update checks on nixos report nothing to do, not an error', function () use ($expect) {
        $result = CheckUpdates::run(new Server('nixos'));
        // ServerPatchCheckJob notifies the team on any error key, weekly, per server.
        $expect(! isset($result['error']), 'a NixOS host must not raise '.($result['error'] ?? ''));
        $expect(($result['total_updates'] ?? null) === 0, 'a flake-managed host has no per-package updates');
    });

    $check('update checks on an apt host still resolve a package manager', function () use ($expect) {
        $result = CheckUpdates::run(new Server('ubuntu'));
        $expect(($result['package_manager'] ?? null) === 'apt', 'the apt branch must be untouched');
    });

    if ($failures !== []) {
        printf("\n%d failed: %s\n", count($failures), implode(', ', $failures));
        exit(1);
    }

    echo "\nall checks passed\n";
}
