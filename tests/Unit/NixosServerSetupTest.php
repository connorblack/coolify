<?php

use App\Actions\Server\InstallDocker;
use App\Actions\Server\InstallPrerequisites;

it('lists nixos as a supported operating system', function () {
    expect(collect(SUPPORTED_OS)->contains('nixos'))->toBeTrue();
});

it('generates guidance-only NixOS Docker installation output', function () {
    $installDocker = new InstallDocker;

    $reflection = new ReflectionClass($installDocker);
    $method = $reflection->getMethod('getNixosDockerInstallCommand');
    $method->setAccessible(true);

    $command = $method->invoke($installDocker);

    expect($command)->toContain('NixOS Docker Configuration Guide');
    expect($command)->toContain('virtualisation.docker');
    expect($command)->toContain('enable = true');
    // Guidance only: nothing here may mutate the host.
    expect($command)->not->toContain('nix-channel');
    expect($command)->not->toContain('nixos-rebuild switch');
    expect($command)->not->toContain('daemon.json');
});

it('generates guidance-only NixOS prerequisites output', function () {
    $installPrerequisites = new InstallPrerequisites;

    $reflection = new ReflectionClass($installPrerequisites);
    $method = $reflection->getMethod('getNixosPrerequisitesCommand');
    $method->setAccessible(true);

    $command = $method->invoke($installPrerequisites);

    expect($command)->toContain('environment.systemPackages');
    expect($command)->toContain('curl wget git jq');
    // Guidance only: nothing here may install a package or rebuild the host.
    foreach (['nix-env', 'nix-channel', 'nixos-rebuild switch', 'apt', 'dnf', 'zypper', 'pacman'] as $forbidden) {
        expect($command)->not->toContain($forbidden);
    }
});
