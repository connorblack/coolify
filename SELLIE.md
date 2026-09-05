# Sellie build of Coolify

Branch `sellie` = the upstream release tag (currently `v4.3.17`) plus the commits below. It exists so
NixOS hosts can be Coolify servers before coollabsio/coolify#7170 (NixOS support) is merged and
released. When that happens, delete the `nixos:` commits and this image is no longer needed.

## Commits on top of the tag

| Commit | Files | From #7170? |
|---|---|---|
| `nixos: accept ID=nixos as a supported OS` | `bootstrap/helpers/constants.php` | yes, unchanged |
| `nixos: guidance-only Docker step` | `app/Actions/Server/InstallDocker.php`, `tests/Unit/NixosServerSetupTest.php` | yes, adapted: returns before the daemon.json merge and `systemctl restart docker`, which are invalid on NixOS; guidance text points at `virtualisation.docker` |
| `nixos: validation messages when Docker is missing` | `app/Livewire/Server/ValidateAndInstall.php` | hunks 2 and 3 only |
| `nixos: guidance-only prerequisites step` | `app/Actions/Server/InstallPrerequisites.php`, `tests/Unit/NixosServerSetupTest.php` | no, a gap #7170 leaves open |
| `nixos: report no updates instead of an error` | `app/Actions/Server/CheckUpdates.php` | no, it replaces the dropped #7170 hunk |
| `check: sellie-nixos-check` | `scripts/sellie-nixos-check.php` | no |
| `ci: sellie image` | `.github/workflows/sellie-image.yml` | no |
| `docs: SELLIE.md` | this file | no |

Dropped from #7170 on purpose: its `UpdatePackage.php` and `CheckUpdates.php` hunks,
`patches.blade.php`, `NixosPatchCheckTest.php` (they run `nix-channel --update nixos` and
`nixos-rebuild switch`; the Sellie hosts are flake-based, so those commands would fail or rebuild
from the wrong source), and hunk 1 of `ValidateAndInstall.php` (it stored an error string in
`validation_logs` on a healthy host).

Dropping the `CheckUpdates.php` hunk and adding nothing back was a mistake. `ServerPatchCheckJob`
runs weekly for every server and notifies the team whenever the result carries an `error` key, so
every NixOS server raised "Unsupported package manager" each Sunday. NixOS now maps to a `nix`
package manager that reports zero updates and no error. The job stays quiet, the Patches page shows
nothing to do, and `UpdatePackage` still answers "OS not supported" if anything asks it to patch a
package, so nothing runs on the host.

"Validate & Install" is safe on a NixOS server. Both install steps are guidance only: Docker points
at `virtualisation.docker`, prerequisites point at `environment.systemPackages`. Before that, a
NixOS host missing `git` or `jq` reached `InstallPrerequisites`, which had no NixOS branch and threw
`Unsupported OS type for prerequisites installation` into a Livewire request. The earlier
instruction to use plain validation instead was not followable:
`resources/views/livewire/server/show.blade.php` mounts the component without `:install`, and the
property defaults to true.

## Image

`ghcr.io/connorblack/coolify:<version>` (plus `<version>-sellie.<sha7>`), built by
`.github/workflows/sellie-image.yml` from `docker/production/Dockerfile` with
`COOLIFY_VERSION=<version>` on tags `sellie/v<version>`. The tag equals the upstream version so the
instance's updater (`upgrade.sh <version> …`) finds it. The instance selects it through the
documented persistent override `/data/coolify/source/docker-compose.custom.yml`:

```yaml
services:
  coolify:
    image: "ghcr.io/connorblack/coolify:${LATEST_IMAGE:-4.3.17}"
```

`REGISTRY_URL` stays `docker.io`; helper, realtime and sentinel images stay vendor images.
Deleting the file returns the instance to the vendor image on the next update.

## Following an upstream release

```
git fetch upstream --tags
git rebase --onto vX.Y.Z vPREV sellie
git diff vX.Y.Z..sellie --stat            # only the files in the table above
grep -rn "nix-channel\|nixos-rebuild switch" app/   # must be empty
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli-alpine php bootstrap/getVersion.php   # X.Y.Z
docker run --rm -v "$PWD:/app" -w /app php:8.4-cli-alpine php scripts/sellie-nixos-check.php
git tag -a sellie/vX.Y.Z -m "Sellie build of Coolify X.Y.Z" && git push origin sellie sellie/vX.Y.Z
```

`sellie-nixos-check.php` drives the real action classes through stubbed seams, so it needs no
Composer install and no database. It is the check that catches an upstream rebase quietly taking
the NixOS branches away. Pest covers the guidance strings in `tests/Unit/NixosServerSetupTest.php`
when a vendor directory exists.

Review upstream's changes to `scripts/upgrade.sh`, `docker-compose*.yml`, `.env.production`,
`app/Actions/Server/{InstallDocker,ValidateServer}.php` and `app/Models/Server.php::validateOS`
between the tags; any of them can change the assumptions above. After CI publishes the image,
run the update from the Coolify dashboard (Settings → Updates). Automatic updates stay disabled so
an update never runs before its image exists; a missing tag makes `upgrade.sh` abort before any
container is stopped.
