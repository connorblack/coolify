<?php

use App\Actions\Server\InstallDocker;

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
